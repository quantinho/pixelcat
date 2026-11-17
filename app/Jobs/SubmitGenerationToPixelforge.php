<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Events\GenerationFinished;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use App\Pixelforge\PixelforgeUnavailable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends one `submitting` generation to Pixelforge and handles every outcome of the create
 * request as in the spec's Failure Modes table (FR-008, research R9):
 *
 * - 202: applied through ApplyGenerationSnapshot, the only writer once Pixelforge has it.
 * - Concurrency 429: back to `waiting`; the dispatcher retries after Retry-After.
 * - Rate limit 429 and overload 503: retried after Retry-After plus backoff, then failed.
 * - Other definite rejections: failed, hold released.
 * - Timeout, connection error, other 5xx (ambiguous): Pixelforge may have created it, so it
 *   stays `submitting` and is resent with the same Idempotency-Key until a definite answer.
 *
 * Before Pixelforge has the generation this job may change its status itself, always under
 * the row lock. A generation with an unresolved ambiguous attempt is never failed here; a
 * later definite answer that proves nothing was created resolves it (RESOLVES_AMBIGUITY).
 */
class SubmitGenerationToPixelforge implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Rate limits and overloads give up after this many attempts. */
    public const MAX_BUSY_ATTEMPTS = 5;

    private const MAX_BACKOFF_SECONDS = 10 * 60;

    /** The longest delay SQS accepts. */
    private const MAX_DELAY_SECONDS = 15 * 60;

    /**
     * Answers that prove an earlier ambiguous attempt created nothing (spec glossary): limits
     * are applied after Pixelforge's idempotent replay, and content and validation checks
     * give the same answer for the same parameters. Rate limits, overloads, a bad API key and
     * a reused key prove nothing, so they leave the attempt ambiguous.
     */
    private const RESOLVES_AMBIGUITY = [
        'concurrency_limit_exceeded',
        'daily_limit_exceeded',
        'content_policy_violation',
        'validation_failed',
    ];

    /** Retries are counted and capped by this job itself (see release()). */
    public int $tries = 0;

    /** Unexpected exceptions (e.g. the database is down) still stop eventually. */
    public int $maxExceptions = 5;

    public int $backoff = 30;

    /**
     * Ambiguous attempts are retried until Pixelforge answers, so the lock must outlive a
     * long outage, or the hourly sweep would queue a second job beside the retrying one.
     * The worker releases the lock when the job finishes or fails, and a crashed worker's
     * job comes back from the queue still holding it. Only a job that vanished from the
     * queue leaves a stale lock; after a day it lapses and the sweep requeues the
     * generation. Even a duplicate job would be harmless: it sends the same
     * Idempotency-Key and applies the result through ApplyGenerationSnapshot.
     */
    public int $uniqueFor = 24 * 60 * 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function handle(PixelforgeClient $pixelforge, ApplyGenerationSnapshot $apply, Ledger $ledger): void
    {
        $generation = $this->generation->refresh();

        // Back to waiting, already accepted (a webhook can beat our 202) or final.
        if ($generation->status !== GenerationStatus::Submitting) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $snapshot = $pixelforge->createGeneration($generation);
        } catch (PixelforgeUnavailable $e) {
            $this->retryAmbiguous($generation, $e);

            return;
        } catch (PixelforgeRejected $e) {
            $this->handleRejection($generation, $e, $ledger);

            return;
        }

        $apply($generation, $snapshot, 'submit');
    }

    private function retryAmbiguous(Generation $generation, PixelforgeUnavailable $e): void
    {
        $delay = $this->backoffSeconds();

        Generation::query()->whereKey($generation->id)->update(['had_ambiguous_attempt' => true]);
        $generation->recordEvent('retrying', "Pixelforge didn't answer. Checking again…", ['retry_in' => $delay]);

        Log::warning('Ambiguous Pixelforge create; resending with the same Idempotency-Key.', [
            'generation_id' => $generation->id,
            'attempt' => $this->attempts(),
            'reason' => $e->getMessage(),
        ]);

        $this->release($delay);
    }

    private function handleRejection(Generation $generation, PixelforgeRejected $e, Ledger $ledger): void
    {
        if ($e->errorCode === 'concurrency_limit_exceeded') {
            $this->backToWaiting($generation, $e->retryAfter ?? 5);

            return;
        }

        $busy = in_array($e->errorCode, ['rate_limit_exceeded', 'overloaded'], true);

        // Limits and overloads pass; give them a few tries. After an ambiguous attempt we
        // must not give up at all, because only Pixelforge can tell us what happened.
        if ($busy && ($this->attempts() < self::MAX_BUSY_ATTEMPTS || $generation->had_ambiguous_attempt)) {
            $delay = min(self::MAX_DELAY_SECONDS, ($e->retryAfter ?? 0) + $this->backoffSeconds());
            $message = $e->errorCode === 'overloaded' ? 'Pixelforge is busy, retrying…' : 'Waiting for Pixelforge…';
            $generation->recordEvent('retrying', $message, ['error_code' => $e->errorCode, 'retry_in' => $delay]);

            $this->release($delay);

            return;
        }

        $this->fail($generation, $e, $ledger);
    }

    /**
     * Pixelforge replays an existing generation before it applies limits, so a concurrency
     * 429 means nothing exists there: the generation waits for a slot again.
     */
    private function backToWaiting(Generation $generation, int $retryAfter): void
    {
        $retryAfter = min(self::MAX_DELAY_SECONDS, max(1, $retryAfter));

        DB::transaction(function () use ($generation, $retryAfter) {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GenerationStatus::Submitting) {
                return;
            }

            // Nothing exists at Pixelforge, so it may expire like any waiting generation.
            $locked->had_ambiguous_attempt = false;
            $locked->status = GenerationStatus::Waiting;
            $locked->available_at = now()->addSeconds($retryAfter);
            $locked->save();

            $locked->recordEvent('slot_wait', GenerationStatus::Waiting->label(), ['retry_in' => $retryAfter]);
        });

        DispatchWaiting::dispatch()->delay($retryAfter);
    }

    private function fail(Generation $generation, PixelforgeRejected $e, Ledger $ledger): void
    {
        $context = [
            'generation_id' => $generation->id,
            'error_code' => $e->errorCode,
            'http_status' => $e->httpStatus,
        ];

        $resolvesAmbiguity = in_array($e->errorCode, self::RESOLVES_AMBIGUITY, true);

        // Pixelforge may still hold (and bill) an earlier attempt; failing it here could leave
        // an image we never charge for. It stays submitting; the hourly sweep resends it with
        // the same key and an operator looks at the log.
        if ($generation->had_ambiguous_attempt && ! $resolvesAmbiguity) {
            Log::critical('Pixelforge rejected a generation that had an ambiguous attempt; leaving it submitting.', $context);
            $generation->recordEvent('retrying', 'Still checking with Pixelforge…', ['error_code' => $e->errorCode]);

            return;
        }

        match ($e->errorCode) {
            'invalid_api_key' => Log::critical('Pixelforge rejected our API key.', $context),
            'validation_failed', 'idempotency_key_reused' => Log::error('Pixelforge rejected our create request; this is our bug.', $context),
            default => Log::info('Pixelforge rejected a generation.', $context),
        };

        $failed = DB::transaction(function () use ($generation, $e, $ledger, $resolvesAmbiguity) {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($resolvesAmbiguity) {
                $locked->had_ambiguous_attempt = false;
            }

            // Only this job sets had_ambiguous_attempt, but check under the lock anyway.
            if ($locked->status !== GenerationStatus::Submitting || $locked->had_ambiguous_attempt) {
                return null;
            }

            $locked->status = GenerationStatus::Failed;
            $locked->error_code = $e->errorCode;
            $locked->error_message = $e->getMessage();
            $locked->save();

            $ledger->release($locked);
            $locked->recordEvent('failed', GenerationResource::errorMessage($e->errorCode), ['error_code' => $e->errorCode]);

            return $locked;
        });

        if ($failed !== null) {
            GenerationFinished::dispatch($failed);
        }
    }

    /**
     * 5 s × 2^(attempt − 1), at most 10 minutes.
     */
    private function backoffSeconds(): int
    {
        $exponent = min(max($this->attempts() - 1, 0), 10);

        return min(self::MAX_BACKOFF_SECONDS, 5 * 2 ** $exponent);
    }
}
