<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use App\Pixelforge\PixelforgeUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The polling fallback for one generation (spec FR-011): a delayed check that fetches the
 * generation from Pixelforge only if no update arrived since it was scheduled, then schedules
 * the next check, until the generation is final.
 *
 * Checks are 2, 4, 8 and then every 15 minutes apart; no delay exceeds the 15-minute SQS limit.
 * After 24 hours the chain stops (an idle app must let the database sleep); the hourly
 * pixelforge:poll sweep keeps trying from there.
 */
class SyncGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Minutes before each check; the last value repeats. */
    public const DELAYS = [2, 4, 8, 15];

    /** Leave this many requests per minute for new submissions. */
    public const MIN_REMAINING_REQUESTS = 20;

    /** Released early arrivals and the odd database error. */
    public int $tries = 5;

    public int $uniqueFor = 16 * 60;

    /** Stop checking this long after Pixelforge accepted the generation. */
    public const GIVE_UP_AFTER_HOURS = 24;

    public function __construct(
        public string $generationId,
        public int $step,
        public CarbonImmutable $scheduledAt,
        public ?CarbonImmutable $firstScheduledAt = null,
    ) {}

    /**
     * Schedule check number $step (0 = the first, 2 minutes from now).
     */
    public static function schedule(string $generationId, int $step = 0, ?CarbonImmutable $firstScheduledAt = null): PendingDispatch
    {
        $now = CarbonImmutable::now();
        $job = new self($generationId, $step, $now, $firstScheduledAt ?? $now);

        return dispatch($job)->delay($job->delayMinutes() * 60);
    }

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function delayMinutes(): int
    {
        return self::DELAYS[min($this->step, count(self::DELAYS) - 1)];
    }

    public function dueAt(): CarbonImmutable
    {
        return $this->scheduledAt->addMinutes($this->delayMinutes());
    }

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $apply): void
    {
        // Never act early (clock skew, or a queue that ignores delays, like "sync").
        $early = (int) ceil(CarbonImmutable::now()->diffInSeconds($this->dueAt(), false));
        if ($early > 0) {
            $this->release(min($early, 15 * 60));

            return;
        }

        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status->isFinal() || $generation->pixelforge_id === null) {
            return;
        }

        if (! $this->updatedSinceScheduled($generation) && $this->hasBudget($client)) {
            $this->fetchAndApply($generation, $client, $apply);

            if ($generation->status->isFinal()) {
                return;
            }
        }

        if ($this->pastCeiling($generation)) {
            $this->giveUp($generation);

            return;
        }

        self::schedule($this->generationId, $this->step + 1, $this->firstScheduledAt ?? $this->scheduledAt);
    }

    /**
     * 24 hours after Pixelforge created the generation, or after our first check when
     * Pixelforge never told us when that was.
     */
    private function pastCeiling(Generation $generation): bool
    {
        $start = $generation->provider_created_at !== null
            ? CarbonImmutable::instance($generation->provider_created_at)
            : ($this->firstScheduledAt ?? $this->scheduledAt);

        return CarbonImmutable::now()->greaterThanOrEqualTo($start->addHours(self::GIVE_UP_AFTER_HOURS));
    }

    private function giveUp(Generation $generation): void
    {
        $generation->recordEvent('sync_stopped', 'Still waiting for Pixelforge after a day. We keep checking hourly.', [
            'status' => $generation->status->value,
        ]);

        Log::critical('Stopped syncing a generation that is not final after 24 hours; the hourly sweep keeps trying.', [
            'generation_id' => $generation->id,
            'pixelforge_id' => $generation->pixelforge_id,
            'status' => $generation->status->value,
        ]);
    }

    private function updatedSinceScheduled(Generation $generation): bool
    {
        return $generation->last_synced_at !== null && $generation->last_synced_at->greaterThan($this->scheduledAt);
    }

    private function hasBudget(PixelforgeClient $client): bool
    {
        $remaining = $client->remainingRequests();

        return $remaining === null || $remaining >= self::MIN_REMAINING_REQUESTS;
    }

    private function fetchAndApply(Generation $generation, PixelforgeClient $client, ApplyGenerationSnapshot $apply): void
    {
        try {
            $apply($generation, $client->getGeneration((string) $generation->pixelforge_id), 'poll');
        } catch (PixelforgeRejected $e) {
            $context = ['generation_id' => $generation->id, 'pixelforge_id' => $generation->pixelforge_id, 'error_code' => $e->errorCode];

            // Our key is wrong, or Pixelforge doesn't know a generation it accepted: a human must look.
            in_array($e->errorCode, ['invalid_api_key', 'not_found'], true)
                ? Log::critical('Pixelforge refused to show a generation.', $context)
                : Log::warning('Pixelforge refused a sync request; trying later.', $context);
        } catch (PixelforgeUnavailable $e) {
            Log::warning('Pixelforge did not answer a sync request; trying later.', [
                'generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // A snapshot we can't apply needs a human; the chain keeps going so a fix applies.
            report($e);
            Log::critical('Could not apply a synced Pixelforge snapshot; trying later.', [
                'generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
