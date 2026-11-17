<?php

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Events\GenerationFinished;
use App\Events\GenerationSucceeded;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePixelforge;

/**
 * The polling fallback (FR-011, US2 scenario 4): each accepted generation gets its own delayed
 * sync, and an hourly sweep catches anything a lost job missed.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $this->pixelforge = FakePixelforge::fake();
    $this->user = User::factory()->create();
});

/** A generation that Pixelforge has (stored in the fake) in the given status. */
function generationAtPixelforge(FakePixelforge $pixelforge, User $user, string $state, string $remoteStatus): Generation
{
    $generation = Generation::factory()->for($user)->{$state}()->create(['estimate_max' => 500_000_000]);
    app(Ledger::class)->hold($generation, 500_000_000);
    $pixelforge->store(FakePixelforge::generationFor($generation, $remoteStatus));

    return $generation;
}

/** Run a sync job the way the queue would, once it is due. */
function runSync(SyncGeneration $job): void
{
    test()->travelTo($job->dueAt());
    app()->call([$job, 'handle']);
}

describe('SyncGeneration', function () {
    beforeEach(fn () => Queue::fake([SyncGeneration::class]));

    it('is scheduled two minutes after Pixelforge accepts a generation', function () {
        $generation = Generation::factory()->for($this->user)->submitting()->create(['pixelforge_id' => null]);
        $snapshot = GenerationSnapshot::fromArray(FakePixelforge::generationFor($generation, 'queued'));

        app(ApplyGenerationSnapshot::class)($generation, $snapshot, 'submit');

        Queue::assertPushed(SyncGeneration::class, 1);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $job) => $job->generationId === $generation->id
            && $job->step === 0
            && $job->delay === 120
            && $job->dueAt()->equalTo(now()->addMinutes(2)));
    });

    it('is unique per generation', function () {
        $generation = Generation::factory()->for($this->user)->queued()->create();

        SyncGeneration::schedule($generation->id);
        SyncGeneration::schedule($generation->id);

        expect(new SyncGeneration($generation->id, 0, now()))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
            ->and((new SyncGeneration($generation->id, 0, now()))->uniqueId())->toBe($generation->id);
        Queue::assertPushed(SyncGeneration::class, 1);
    });

    it('fetches the generation and applies it when no update arrived', function () {
        Event::fake([GenerationSucceeded::class, GenerationFinished::class]);
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'queued', 'succeeded');

        runSync(new SyncGeneration($generation->id, 0, now()));

        $generation->refresh();
        Http::assertSentCount(1);
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged)->toBe(FakePixelforge::WORKED_EXAMPLE_USER_PRICE)
            ->and($generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count())->toBe(1)
            ->and($generation->events()->where('type', 'succeeded')->sole()->data)->toMatchArray(['source' => 'poll']);
        Queue::assertNothingPushed();
    });

    it('does nothing once the generation is final', function (string $state) {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, $state, 'succeeded');

        runSync(new SyncGeneration($generation->id, 0, now()));

        Http::assertSentCount(0);
        Queue::assertNothingPushed();
    })->with(['succeeded', 'failed']);

    it('does nothing for a generation that no longer exists', function () {
        runSync(new SyncGeneration('0199a1b2-0000-7000-8000-000000000000', 0, now()));

        Http::assertSentCount(0);
        Queue::assertNothingPushed();
    });

    it('does not fetch when an update arrived after it was scheduled, but keeps watching', function () {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'queued', 'processing');
        $job = new SyncGeneration($generation->id, 0, now());
        $this->travel(1)->minutes();
        $generation->update(['status' => GenerationStatus::Processing, 'last_synced_at' => now()]);

        runSync($job);

        Http::assertSentCount(0);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next) => $next->step === 1 && $next->delay === 240);
    });

    it('does not act before it is due', function () {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'queued', 'succeeded');
        $job = (new SyncGeneration($generation->id, 0, now()))->withFakeQueueInteractions();

        $this->travel(90)->seconds();
        $job->handle(app(PixelforgeClient::class), app(ApplyGenerationSnapshot::class));

        Http::assertSentCount(0);
        $job->assertReleased(30);
        expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('reschedules itself with backoff 2, 4, 8, then every 15 minutes', function (int $step, int $nextStep, int $delayMinutes) {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'queued', 'processing');

        runSync(new SyncGeneration($generation->id, $step, now()));

        Http::assertSentCount(1);
        expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next) => $next->step === $nextStep
            && $next->delay === $delayMinutes * 60
            && $next->delay <= 15 * 60);
    })->with([
        'after the first check (+2)' => [0, 1, 4],
        'after +4' => [1, 2, 8],
        'after +8' => [2, 3, 15],
        'after +15' => [3, 4, 15],
        'much later' => [12, 13, 15],
    ]);

    it('skips Pixelforge when fewer than 20 requests are left this minute, and tries later', function () {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'queued', 'succeeded');
        $job = new SyncGeneration($generation->id, 0, now());
        $this->travelTo($job->dueAt());
        // Another request just told us how much of this minute's budget is left.
        $this->pixelforge->withRateLimitRemaining(19);
        app(PixelforgeClient::class)->getGeneration((string) $generation->pixelforge_id);

        app()->call([$job, 'handle']);

        Http::assertSentCount(1);
        expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next) => $next->step === 1);
    });

    it('gives up after 24 hours at Pixelforge and leaves the rest to the hourly sweep', function () {
        Log::spy();
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'processing');
        $generation->update(['provider_created_at' => now()->subHours(24)->subMinutes(10)]);

        runSync(new SyncGeneration($generation->id, 99, now()));

        Http::assertSentCount(1);
        Queue::assertNothingPushed();
        expect($generation->fresh()->status)->toBe(GenerationStatus::Processing)
            ->and($generation->events()->where('type', 'sync_stopped')->count())->toBe(1);
        Log::shouldHaveReceived('critical')->once();
    });

    it('counts the 24 hours from its first check when Pixelforge sent no creation time', function () {
        Log::spy();
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'processing');
        $generation->update(['provider_created_at' => null]);
        $this->pixelforge->store(FakePixelforge::generationFor($generation, 'processing', ['created_at' => null]));

        runSync(new SyncGeneration($generation->id, 99, now(), firstScheduledAt: now()->subHours(25)));

        Queue::assertNothingPushed();
        expect($generation->events()->where('type', 'sync_stopped')->count())->toBe(1);
        Log::shouldHaveReceived('critical')->once();
    });

    it('carries its first check time along while it keeps watching', function () {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'processing');
        $generation->update(['provider_created_at' => null]);
        $first = now()->subHours(3);

        runSync(new SyncGeneration($generation->id, 5, now(), firstScheduledAt: $first));

        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next) => $next->step === 6
            && $next->firstScheduledAt?->equalTo($first));
    });

    it('leaves the state unchanged and tries later when Pixelforge errs', function (string $outcome, bool $critical) {
        Log::spy();
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded');
        $this->pixelforge->getReturns($outcome);

        runSync(new SyncGeneration($generation->id, 0, now()));

        expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next) => $next->step === 1);
        $critical
            ? Log::shouldHaveReceived('critical')->once()
            : Log::shouldNotHaveReceived('critical');
    })->with([
        'not_found' => ['not_found', true],
        'invalid_api_key' => ['invalid_api_key', true],
        'rate_limit_exceeded' => ['rate_limit_exceeded', false],
        'timeout' => [FakePixelforge::TIMEOUT, false],
        'server error' => [FakePixelforge::SERVER_ERROR, false],
    ]);
});

describe('pixelforge:poll', function () {
    beforeEach(fn () => Event::fake([GenerationSucceeded::class, GenerationFinished::class]));

    it('fetches queued and processing generations with no update for 15 minutes', function () {
        $staleQueued = generationAtPixelforge($this->pixelforge, $this->user, 'queued', 'succeeded');
        $staleProcessing = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'failed');
        $fresh = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded');
        $done = generationAtPixelforge($this->pixelforge, $this->user, 'succeeded', 'succeeded');
        $staleQueued->update(['last_synced_at' => now()->subMinutes(16)]);
        $staleProcessing->update(['last_synced_at' => now()->subMinutes(30)]);
        $fresh->update(['last_synced_at' => now()->subMinutes(14)]);
        $done->update(['last_synced_at' => now()->subHours(3)]);

        $this->artisan('pixelforge:poll')->assertSuccessful();

        Http::assertSentCount(2);
        expect($staleQueued->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($staleProcessing->fresh()->status)->toBe(GenerationStatus::Failed)
            ->and($fresh->fresh()->status)->toBe(GenerationStatus::Processing)
            ->and($staleQueued->fresh()->events()->where('type', 'succeeded')->sole()->data)->toMatchArray(['source' => 'poll']);
    });

    it('fetches at most 10 per run, the longest silent first', function () {
        $generations = collect(range(1, 12))->map(function (int $minutes) {
            $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded');
            $generation->update(['last_synced_at' => now()->subMinutes(20 + $minutes)]);

            return $generation;
        });

        $this->artisan('pixelforge:poll')->assertSuccessful();

        Http::assertSentCount(10);
        expect($generations->take(2)->map(fn (Generation $g) => $g->fresh()->status)->all())
            ->toBe([GenerationStatus::Processing, GenerationStatus::Processing]);
    });

    it('stops when fewer than 20 requests are left this minute', function () {
        $this->pixelforge->withRateLimitRemaining(19);
        foreach (range(1, 3) as $i) {
            generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded')
                ->update(['last_synced_at' => now()->subMinutes(20)]);
        }

        $this->artisan('pixelforge:poll')->assertSuccessful();

        Http::assertSentCount(1);
    });

    it('leaves the state unchanged and logs critical on invalid_api_key or not_found', function (string $code) {
        Log::spy();
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded');
        $generation->update(['last_synced_at' => now()->subMinutes(20)]);
        $this->pixelforge->getReturns($code);

        $this->artisan('pixelforge:poll')->assertSuccessful();

        expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);
        Log::shouldHaveReceived('critical')->once();
    })->with(['invalid_api_key', 'not_found']);

    it('records a failed attempt so the generation goes to the back of the line', function (string $outcome) {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded');
        $generation->update(['last_synced_at' => now()->subMinutes(20)]);
        $this->pixelforge->getReturns($outcome);

        $this->artisan('pixelforge:poll')->assertSuccessful();

        expect($generation->fresh()->status)->toBe(GenerationStatus::Processing)
            ->and($generation->fresh()->last_synced_at->equalTo(now()))->toBeTrue();
    })->with(['not_found' => 'not_found', 'timeout' => FakePixelforge::TIMEOUT, 'server error' => FakePixelforge::SERVER_ERROR]);

    it('does not record an attempt when our key or budget is the problem', function () {
        $generation = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded');
        $generation->update(['last_synced_at' => now()->subMinutes(20)]);
        $this->pixelforge->getReturns('invalid_api_key');

        $this->artisan('pixelforge:poll')->assertSuccessful();

        expect($generation->fresh()->last_synced_at->equalTo(now()->subMinutes(20)))->toBeTrue();
    });

    it('reaches a healthy generation behind 10 permanently broken ones', function () {
        Log::spy();
        foreach (range(1, 10) as $i) {
            // Pixelforge doesn't know these (not_found on every fetch).
            Generation::factory()->for($this->user)->processing()->create(['last_synced_at' => now()->subHours(2)]);
        }
        $healthy = generationAtPixelforge($this->pixelforge, $this->user, 'processing', 'succeeded');
        $healthy->update(['last_synced_at' => now()->subMinutes(20)]);

        $this->artisan('pixelforge:poll')->assertSuccessful();
        expect($healthy->fresh()->status)->toBe(GenerationStatus::Processing);

        $this->travel(1)->hours();
        $this->artisan('pixelforge:poll')->assertSuccessful();

        expect($healthy->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('re-dispatches generations stuck in submitting, and leaves recent ones alone', function () {
        Queue::fake();
        $stuck = Generation::factory()->for($this->user)->submitting()->create(['pixelforge_id' => null]);
        $recent = Generation::factory()->for($this->user)->submitting()->create(['pixelforge_id' => null]);
        Generation::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->artisan('pixelforge:poll')->assertSuccessful();

        Queue::assertPushed(SubmitGenerationToPixelforge::class, 1);
        Queue::assertPushed(SubmitGenerationToPixelforge::class, fn ($job) => $job->generation->is($stuck));
        Queue::assertNotPushed(SubmitGenerationToPixelforge::class, fn ($job) => $job->generation->is($recent));
    });
});
