<?php

use App\Actions\Generations\DispatchWaitingGenerations;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Events\GenerationFinished;
use App\Jobs\DispatchWaiting;
use App\Jobs\ExpireWaitingGeneration;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Fair slot dispatching (FR-006, FR-011, research R7): at most 3 active generations for the
 * account and 2 per user, oldest first, only once `available_at` has passed. Generations that
 * waited longer than the limit fail without a charge.
 */
beforeEach(function () {
    config(['pixelcat.max_active' => 3, 'pixelcat.max_active_per_user' => 2, 'pixelcat.max_wait_minutes' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    Queue::fake();

    $this->ledger = app(Ledger::class);
    $this->dispatch = fn () => app(DispatchWaitingGenerations::class)();

    // A waiting generation with its hold, created `$minutesAgo` minutes ago.
    $this->waiting = function (User $user, int $minutesAgo = 1, array $attributes = []): Generation {
        $generation = Generation::factory()->for($user)->waiting()->create([
            'created_at' => now()->subMinutes($minutesAgo),
            'available_at' => now()->subMinutes($minutesAgo),
            ...$attributes,
        ]);
        app(Ledger::class)->grant($user, $generation->estimate_max, 'Welcome credit');
        app(Ledger::class)->hold($generation, $generation->estimate_max);

        return $generation;
    };

    $this->statusOf = fn (Generation $generation) => $generation->fresh()->status;
    $this->submitted = fn () => Queue::pushed(SubmitGenerationToPixelforge::class)
        ->map(fn (SubmitGenerationToPixelforge $job) => $job->generation->id)
        ->all();
});

it('moves waiting generations to submitting and queues one submit job each', function () {
    $first = ($this->waiting)(User::factory()->create(), 3);
    $second = ($this->waiting)(User::factory()->create(), 2);

    expect(($this->dispatch)())->toBe(['dispatched' => 2, 'expired' => 0]);

    expect(($this->statusOf)($first))->toBe(GenerationStatus::Submitting)
        ->and(($this->statusOf)($second))->toBe(GenerationStatus::Submitting)
        ->and(($this->submitted)())->toBe([$first->id, $second->id]);
});

it('keeps at most 3 generations active for the account, oldest first', function () {
    $generations = collect(range(5, 1))->map(fn (int $minutesAgo) => ($this->waiting)(User::factory()->create(), $minutesAgo));

    ($this->dispatch)();

    expect($generations->map(fn (Generation $g) => ($this->statusOf)($g)->value)->all())
        ->toBe(['submitting', 'submitting', 'submitting', 'waiting', 'waiting'])
        ->and(($this->submitted)())->toBe($generations->take(3)->pluck('id')->all());
});

it('counts generations already with Pixelforge against the account limit', function () {
    Generation::factory()->queued()->create();
    Generation::factory()->processing()->create();
    $first = ($this->waiting)(User::factory()->create(), 2);
    $second = ($this->waiting)(User::factory()->create(), 1);

    ($this->dispatch)();

    expect(($this->statusOf)($first))->toBe(GenerationStatus::Submitting)
        ->and(($this->statusOf)($second))->toBe(GenerationStatus::Waiting);
});

it('dispatches nothing when every slot is taken', function () {
    Generation::factory()->count(2)->queued()->create();
    Generation::factory()->submitting()->create();
    $waiting = ($this->waiting)(User::factory()->create());

    expect(($this->dispatch)())->toBe(['dispatched' => 0, 'expired' => 0])
        ->and(($this->statusOf)($waiting))->toBe(GenerationStatus::Waiting);
    Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
});

it('gives one user at most 2 active generations and the free slot to the next user', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $alices = collect([10, 9, 8, 7])->map(fn (int $minutesAgo) => ($this->waiting)($alice, $minutesAgo));
    $bobs = ($this->waiting)($bob, 1);

    ($this->dispatch)();

    expect($alices->map(fn (Generation $g) => ($this->statusOf)($g)->value)->all())
        ->toBe(['submitting', 'submitting', 'waiting', 'waiting'])
        ->and(($this->statusOf)($bobs))->toBe(GenerationStatus::Submitting);
});

it('counts a user\'s generations already with Pixelforge against their limit', function () {
    $alice = User::factory()->create();
    Generation::factory()->for($alice)->processing()->create();
    $first = ($this->waiting)($alice, 2);
    $second = ($this->waiting)($alice, 1);

    ($this->dispatch)();

    expect(($this->statusOf)($first))->toBe(GenerationStatus::Submitting)
        ->and(($this->statusOf)($second))->toBe(GenerationStatus::Waiting);
});

it('leaves generations whose available_at is still in the future', function () {
    $later = ($this->waiting)(User::factory()->create(), 2, ['available_at' => now()->addSeconds(5)]);
    $now = ($this->waiting)(User::factory()->create(), 1);

    ($this->dispatch)();

    expect(($this->statusOf)($later))->toBe(GenerationStatus::Waiting)
        ->and(($this->statusOf)($now))->toBe(GenerationStatus::Submitting);

    $this->travel(5)->seconds();
    ($this->dispatch)();

    expect(($this->statusOf)($later))->toBe(GenerationStatus::Submitting);
});

it('fails generations that waited too long, releasing the hold', function () {
    $user = User::factory()->create();
    Generation::factory()->count(3)->queued()->create();
    $old = ($this->waiting)($user, 15);
    $recent = ($this->waiting)($user, 14);
    $balanceBefore = $this->ledger->balance($user);

    expect(($this->dispatch)())->toBe(['dispatched' => 0, 'expired' => 1]);

    $old->refresh();
    expect($old->status)->toBe(GenerationStatus::Failed)
        ->and($old->error_code)->toBe('waited_too_long')
        ->and($old->charged)->toBeNull()
        ->and($old->events()->pluck('message')->last())->toBe("Pixelforge is too busy right now. You weren't charged.")
        ->and(($this->statusOf)($recent))->toBe(GenerationStatus::Waiting)
        ->and(LedgerEntry::query()->where('generation_id', $old->id)->pluck('type')->map->value->all())
        ->toBe([LedgerEntryType::Hold->value, LedgerEntryType::HoldRelease->value])
        ->and($this->ledger->balance($user))->toBe($balanceBefore + $old->estimate_max);
});

it('never fails a waiting generation that had an ambiguous attempt', function () {
    $generation = ($this->waiting)(User::factory()->create(), 30, ['had_ambiguous_attempt' => true]);

    expect(($this->dispatch)())->toBe(['dispatched' => 1, 'expired' => 0])
        ->and(($this->statusOf)($generation))->toBe(GenerationStatus::Submitting);
});

it('runs from the hourly sweep command', function () {
    $old = ($this->waiting)(User::factory()->create(), 20);
    $fresh = ($this->waiting)(User::factory()->create(), 1);

    $this->artisan('pixelforge:dispatch')
        ->expectsOutputToContain('Dispatched 1 generation')
        ->expectsOutputToContain('1 waited too long')
        ->assertSuccessful();

    expect(($this->statusOf)($old))->toBe(GenerationStatus::Failed)
        ->and(($this->statusOf)($fresh))->toBe(GenerationStatus::Submitting);
});

// Event-driven triggers (FR-011) ------------------------------------------------------------

it('queues the dispatcher when a generation finishes', function () {
    GenerationFinished::dispatch(Generation::factory()->succeeded()->create());

    Queue::assertPushed(DispatchWaiting::class, 1);
});

it('runs the dispatcher from the DispatchWaiting job', function () {
    $generation = ($this->waiting)(User::factory()->create());

    app()->call([new DispatchWaiting, 'handle']);

    expect(($this->statusOf)($generation))->toBe(GenerationStatus::Submitting);
});

// ExpireWaitingGeneration ---------------------------------------------------------------------

it('expires a generation that is still waiting at the limit', function () {
    $generation = ($this->waiting)(User::factory()->create(), 15);

    app()->call([new ExpireWaitingGeneration($generation), 'handle']);

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('waited_too_long');
    expect($this->ledger->held($generation))->toBe($generation->estimate_max);
    expect(LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerEntryType::HoldRelease)->exists())->toBeTrue();
    Queue::assertPushed(DispatchWaiting::class);
});

it('does not expire a generation that left the waiting state', function (string $state) {
    $generation = Generation::factory()->{$state}()->create(['created_at' => now()->subMinutes(20)]);
    $before = $generation->fresh()->status;

    app()->call([new ExpireWaitingGeneration($generation), 'handle']);

    expect(($this->statusOf)($generation))->toBe($before)
        ->and($generation->fresh()->error_code)->toBe($state === 'failed' ? 'generation_failed' : null);
})->with(['submitting', 'queued', 'processing', 'succeeded', 'failed']);

it('does not expire a waiting generation that had an ambiguous attempt', function () {
    $generation = ($this->waiting)(User::factory()->create(), 20, ['had_ambiguous_attempt' => true]);

    app()->call([new ExpireWaitingGeneration($generation), 'handle']);

    expect(($this->statusOf)($generation))->toBe(GenerationStatus::Waiting);
});

it('re-dispatches itself with the remaining delay when it runs early', function () {
    config(['pixelcat.max_wait_minutes' => 40]);
    $generation = ($this->waiting)(User::factory()->create(), 15);

    app()->call([new ExpireWaitingGeneration($generation), 'handle']);

    expect(($this->statusOf)($generation))->toBe(GenerationStatus::Waiting);
    Queue::assertPushed(ExpireWaitingGeneration::class, fn (ExpireWaitingGeneration $job) => $job->delay === 15 * 60);

    $this->travel(20)->minutes();
    app()->call([new ExpireWaitingGeneration($generation), 'handle']);

    Queue::assertPushed(ExpireWaitingGeneration::class, fn (ExpireWaitingGeneration $job) => $job->delay === 5 * 60);

    $this->travel(5)->minutes();
    app()->call([new ExpireWaitingGeneration($generation), 'handle']);

    expect(($this->statusOf)($generation))->toBe(GenerationStatus::Failed);
});
