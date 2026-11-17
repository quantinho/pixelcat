<?php

use App\Actions\Generations\DispatchWaitingGenerations;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Events\GenerationAccepted;
use App\Events\GenerationFinished;
use App\Http\Resources\GenerationResource;
use App\Jobs\DispatchWaiting;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePixelforge;

/**
 * Every create-side row of the spec's Failure Modes table (FR-008, SC-005, SC-006).
 *
 * Retry delays: backoff is 5 s × 2^(attempt − 1), at most 10 minutes. Limits and overloads
 * wait Retry-After plus backoff and give up after 5 attempts; ambiguous attempts wait the
 * backoff and never give up. No delay exceeds 15 minutes (the SQS limit).
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    Event::fake([GenerationAccepted::class]);
    Queue::fake();
    $this->pixelforge = FakePixelforge::fake();
    $this->ledger = app(Ledger::class);

    $this->submitting = function (array $attributes = []): Generation {
        $generation = Generation::factory()->submitting()->create($attributes);
        app(Ledger::class)->grant($generation->user, 1_000_000_000, 'Welcome credit');
        app(Ledger::class)->hold($generation, $generation->estimate_max);

        return $generation;
    };

    $this->run = function (Generation $generation, int $attempt = 1): SubmitGenerationToPixelforge {
        $job = (new SubmitGenerationToPixelforge($generation))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        app()->call([$job, 'handle']);

        return $job;
    };

    $this->ledgerTypes = fn (Generation $generation) => LedgerEntry::query()
        ->where('generation_id', $generation->id)->orderBy('id')->pluck('type')->map->value->all();
});

// Definite rejections that end the generation ------------------------------------------------

it('fails the generation for free on a definite rejection', function (string $code) {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns($code);

    $job = ($this->run)($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->charged)->toBeNull()
        ->and($generation->events()->pluck('message')->last())->toBe(GenerationResource::errorMessage($code))
        ->and(($this->ledgerTypes)($generation))->toBe(['hold', 'hold_release'])
        ->and($this->ledger->balance($generation->user))->toBe(1_000_000_000);

    $job->assertNotReleased();
    Queue::assertPushed(DispatchWaiting::class); // the slot it held is free again
})->with([
    'content policy (400)' => 'content_policy_violation',
    'daily limit (429)' => 'daily_limit_exceeded',
    'validation (422)' => 'validation_failed',
    'idempotency key reused (409)' => 'idempotency_key_reused',
    'invalid api key (401)' => 'invalid_api_key',
]);

it('logs rejections that mean a bug or a broken key', function (string $code, string $level) {
    Log::spy();
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns($code);

    ($this->run)($generation);

    Log::shouldHaveReceived($level)->withArgs(fn (string $message, array $context) => $context['error_code'] === $code)->once();
})->with([
    ['validation_failed', 'error'],
    ['idempotency_key_reused', 'error'],
    ['invalid_api_key', 'critical'],
]);

// Overloaded (503) and rate limited (429) ----------------------------------------------------

it('retries an overload after Retry-After with increasing backoff', function (int $attempt, int $delay) {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns('overloaded'); // Retry-After: 2

    ($this->run)($generation, $attempt)->assertReleased($delay);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Submitting)
        ->and($generation->had_ambiguous_attempt)->toBeFalse()
        ->and($generation->events()->latest('id')->first())
        ->type->toBe('retrying')
        ->message->toBe('Pixelforge is busy, retrying…')
        ->and(($this->ledgerTypes)($generation))->toBe(['hold']);
})->with([
    'attempt 1' => [1, 2 + 5],
    'attempt 2' => [2, 2 + 10],
    'attempt 3' => [3, 2 + 20],
    'attempt 4' => [4, 2 + 40],
]);

it('waits at least as long as Retry-After says', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::outcome('overloaded', retryAfter: 30));

    ($this->run)($generation, 2)->assertReleased(30 + 10);
});

it('gives up on an overload after 5 attempts, without a charge', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns('overloaded');

    ($this->run)($generation, 5)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('overloaded')
        ->and(GenerationResource::errorMessage('overloaded'))->toBe("Pixelforge is too busy right now. You weren't charged.")
        ->and(($this->ledgerTypes)($generation))->toBe(['hold', 'hold_release']);
});

it('retries a rate limit after Retry-After and gives up after 5 attempts', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns('rate_limit_exceeded', 'rate_limit_exceeded'); // Retry-After: 20

    ($this->run)($generation, 1)->assertReleased(20 + 5);
    expect($generation->events()->latest('id')->first()->message)->toBe('Waiting for Pixelforge…');

    ($this->run)($generation, 5)->assertNotReleased();
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('rate_limit_exceeded');
});

it('never delays a retry by more than 15 minutes', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::outcome('rate_limit_exceeded', retryAfter: 3600));

    ($this->run)($generation, 1)->assertReleased(900);
});

// Concurrency limit (429) --------------------------------------------------------------------

it('puts the generation back to waiting on a concurrency limit', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns('concurrency_limit_exceeded'); // Retry-After: 5

    ($this->run)($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Waiting)
        ->and($generation->available_at->equalTo(now()->addSeconds(5)))->toBeTrue()
        ->and($generation->events()->latest('id')->first())
        ->type->toBe('slot_wait')
        ->message->toBe('Waiting for a free slot')
        ->and(($this->ledgerTypes)($generation))->toBe(['hold']);

    Queue::assertPushed(DispatchWaiting::class, fn (DispatchWaiting $job) => $job->delay === 5);
});

it('follows Retry-After on a concurrency limit', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::outcome('concurrency_limit_exceeded', retryAfter: 30));

    ($this->run)($generation);

    expect($generation->fresh()->available_at->equalTo(now()->addSeconds(30)))->toBeTrue();
    Queue::assertPushed(DispatchWaiting::class, fn (DispatchWaiting $job) => $job->delay === 30);
});

// Ambiguous attempts -------------------------------------------------------------------------

it('keeps an ambiguous attempt submitting and resends it with backoff', function (string $outcome) {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns($outcome);

    ($this->run)($generation, 1)->assertReleased(5);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Submitting)
        ->and($generation->had_ambiguous_attempt)->toBeTrue()
        ->and($generation->events()->latest('id')->first()->type)->toBe('retrying')
        ->and(($this->ledgerTypes)($generation))->toBe(['hold']);
})->with([
    'timeout' => FakePixelforge::TIMEOUT,
    'other 5xx' => FakePixelforge::SERVER_ERROR,
]);

it('backs off ambiguous retries up to 10 minutes and never gives up', function (int $attempt, int $delay) {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::TIMEOUT);

    ($this->run)($generation, $attempt)->assertReleased($delay);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Submitting);
})->with([
    'attempt 4' => [4, 40],
    'attempt 8' => [8, 600],
    'attempt 50' => [50, 600],
]);

it('never fails a generation after an ambiguous attempt while the answer leaves it unresolved', function (string $code) {
    Log::spy();
    $generation = ($this->submitting)(['had_ambiguous_attempt' => true]);
    $this->pixelforge->createReturns($code);

    ($this->run)($generation, 1);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Submitting)
        ->and($generation->error_code)->toBeNull()
        ->and(($this->ledgerTypes)($generation))->toBe(['hold']);
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context) => $context['error_code'] === $code)->once();
    expect($generation->had_ambiguous_attempt)->toBeTrue();
})->with(['invalid_api_key', 'idempotency_key_reused']);

// A definite answer to a later attempt with the same Idempotency-Key resolves the ambiguity
// (spec glossary): limits are applied after Pixelforge's idempotent replay, and content and
// validation checks give the same answer for the same parameters.

it('resolves an ambiguous attempt with a later definite rejection and fails it for free', function (string $code) {
    Event::fake([GenerationAccepted::class, GenerationFinished::class]);
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::TIMEOUT, $code);

    ($this->run)($generation, 1)->assertReleased(5);
    expect($generation->fresh()->had_ambiguous_attempt)->toBeTrue();

    ($this->run)($generation, 2)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->had_ambiguous_attempt)->toBeFalse()
        ->and(($this->ledgerTypes)($generation))->toBe(['hold', 'hold_release'])
        ->and($this->ledger->balance($generation->user))->toBe(1_000_000_000);
    Event::assertDispatched(GenerationFinished::class, fn (GenerationFinished $event) => $event->generation->is($generation));
})->with(['content_policy_violation', 'daily_limit_exceeded', 'validation_failed']);

it('resolves an ambiguous attempt with a concurrency limit, so it can expire later', function () {
    config(['pixelcat.max_wait_minutes' => 15]);
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::TIMEOUT, 'concurrency_limit_exceeded');

    ($this->run)($generation, 1);
    ($this->run)($generation, 2)->assertNotReleased();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Waiting)
        ->had_ambiguous_attempt->toBeFalse();

    $this->travel(15)->minutes();

    expect(app(DispatchWaitingGenerations::class)->failIfWaitedTooLong($generation))->toBeTrue()
        ->and($generation->fresh()->error_code)->toBe('waited_too_long')
        ->and(($this->ledgerTypes)($generation))->toBe(['hold', 'hold_release']);
});

it('keeps an ambiguous attempt unresolved on a rate limit and retries', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::TIMEOUT, 'rate_limit_exceeded');

    ($this->run)($generation, 1);
    ($this->run)($generation, 2)->assertReleased(20 + 10);

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Submitting)
        ->had_ambiguous_attempt->toBeTrue();
});

it('keeps retrying limits and overloads after an ambiguous attempt instead of giving up', function () {
    $generation = ($this->submitting)(['had_ambiguous_attempt' => true]);
    $this->pixelforge->createReturns('overloaded');

    ($this->run)($generation, 7)->assertReleased(2 + 320);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Submitting);
});

it('gets the same Pixelforge generation back after a timeout that created it', function () {
    $generation = ($this->submitting)();
    $this->pixelforge->createReturns(FakePixelforge::TIMEOUT_AFTER_CREATE);

    ($this->run)($generation, 1)->assertReleased(5);
    $created = $this->pixelforge->storedFor($generation->id);
    expect($created)->not->toBeNull()
        ->and($generation->fresh()->pixelforge_id)->toBeNull();

    ($this->run)($generation, 2)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe($created['id'])
        ->and($generation->had_ambiguous_attempt)->toBeTrue()
        ->and($generation->submit_attempts)->toBe(3)
        ->and($this->pixelforge->createCalls())->toBe(2);
});

it('replays the same Pixelforge generation when the job crashed after acceptance', function () {
    $generation = ($this->submitting)();

    // The first run got the 202 but the worker died before applying it.
    $first = app(PixelforgeClient::class)->createGeneration($generation);

    ($this->run)($generation, 2);

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->pixelforge_id->toBe($first->id);
    expect($this->pixelforge->createCalls())->toBe(2);
});

it('does not resend once a webhook moved the generation on', function () {
    $generation = ($this->submitting)(['had_ambiguous_attempt' => true]);
    $generation->update(['status' => GenerationStatus::Processing]);

    ($this->run)($generation, 3)->assertNotReleased();

    expect($this->pixelforge->createCalls())->toBe(0);
});

// Uniqueness ---------------------------------------------------------------------------------

it('queues at most one submit job per generation', function () {
    $generation = ($this->submitting)();
    $other = ($this->submitting)();

    SubmitGenerationToPixelforge::dispatch($generation);
    SubmitGenerationToPixelforge::dispatch($generation);
    SubmitGenerationToPixelforge::dispatch($other);

    Queue::assertPushed(SubmitGenerationToPixelforge::class, 2);
    expect((new SubmitGenerationToPixelforge($generation))->uniqueId())->toBe($generation->id);
});

it('stays unique for a day of retries, then lets the sweep queue it again', function () {
    $generation = ($this->submitting)();

    SubmitGenerationToPixelforge::dispatch($generation);
    $this->travel(23)->hours();
    SubmitGenerationToPixelforge::dispatch($generation);

    Queue::assertPushed(SubmitGenerationToPixelforge::class, 1);

    // Only a job that vanished without finishing or failing (both release the lock) gets here.
    $this->travel(1)->hours();
    SubmitGenerationToPixelforge::dispatch($generation);

    Queue::assertPushed(SubmitGenerationToPixelforge::class, 2);
});
