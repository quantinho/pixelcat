<?php

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Billing\Ledger;
use App\Enums\DeliveryOutcome;
use App\Enums\GenerationStatus;
use App\Events\GenerationFinished;
use App\Events\GenerationSucceeded;
use App\Models\Generation;
use App\Models\GenerationEvent;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Pixelforge\GenerationSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakePixelforge;

const APPLY_HELD = 500_000_000;

beforeEach(function () {
    config(['pixelcat.markup_percent' => 25]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));

    $this->ledger = app(Ledger::class);
    $this->apply = app(ApplyGenerationSnapshot::class);
    $this->user = User::factory()->create();
    $this->startingBalance = $this->ledger->balance($this->user);
});

/** A generation in the given factory state with its estimate maximum on hold, as after submitting. */
function heldGeneration(User $user, string $state, array $attributes = []): Generation
{
    $generation = Generation::factory()->for($user)->{$state}()->create(['estimate_max' => APPLY_HELD, ...$attributes]);
    app(Ledger::class)->hold($generation, APPLY_HELD);

    return $generation;
}

/** @param array<string, mixed> $overrides */
function snapshotFor(Generation $generation, string $status, array $overrides = []): GenerationSnapshot
{
    return GenerationSnapshot::fromArray(FakePixelforge::generationFor($generation, $status, $overrides));
}

/** @return list<array{type: string, amount: int}> */
function ledgerOf(Generation $generation): array
{
    return LedgerEntry::query()
        ->where('generation_id', $generation->id)
        ->orderBy('id')
        ->get()
        ->map(fn (LedgerEntry $entry) => ['type' => $entry->type->value, 'amount' => (int) $entry->amount])
        ->all();
}

function isoUtc(mixed $time): ?string
{
    return $time?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
}

describe('forward moves', function () {
    it('moves a submitted generation to queued and stores what Pixelforge knows', function () {
        $generation = heldGeneration($this->user, 'submitting', ['pixelforge_id' => null]);

        $outcome = ($this->apply)($generation, snapshotFor($generation, 'queued', [
            'id' => 'gen_queued1',
            'created_at' => '2026-11-17T11:59:30Z',
        ]), 'submit');

        $generation->refresh();
        expect($outcome)->toBe(DeliveryOutcome::Applied)
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->pixelforge_id)->toBe('gen_queued1')
            ->and(isoUtc($generation->provider_created_at))->toBe('2026-11-17T11:59:30Z')
            ->and($generation->rates)->toEqual(FakePixelforge::RATES)
            ->and($generation->last_synced_at)->not->toBeNull();
    });

    it('moves queued to processing and records when it started', function () {
        $generation = heldGeneration($this->user, 'queued');

        $outcome = ($this->apply)($generation, snapshotFor($generation, 'processing', [
            'started_at' => '2026-11-17T11:59:50Z',
        ]), 'webhook');

        $generation->refresh();
        expect($outcome)->toBe(DeliveryOutcome::Applied)
            ->and($generation->status)->toBe(GenerationStatus::Processing)
            ->and(isoUtc($generation->started_at))->toBe('2026-11-17T11:59:50Z');
    });

    it('can skip states', function (string $from, string $to) {
        $generation = heldGeneration($this->user, $from);

        $outcome = ($this->apply)($generation, snapshotFor($generation, $to), 'webhook');

        expect($outcome)->toBe(DeliveryOutcome::Applied)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::from($to));
    })->with([
        'waiting → queued' => ['waiting', 'queued'],
        'submitting → processing' => ['submitting', 'processing'],
        'submitting → succeeded' => ['submitting', 'succeeded'],
        'submitting → failed' => ['submitting', 'failed'],
        'queued → succeeded' => ['queued', 'succeeded'],
        'queued → failed' => ['queued', 'failed'],
    ]);
});

describe('out of order', function () {
    it('ignores a snapshot of the same rank', function () {
        $generation = heldGeneration($this->user, 'processing');

        $outcome = ($this->apply)($generation, snapshotFor($generation, 'processing'), 'webhook');

        expect($outcome)->toBe(DeliveryOutcome::OutOfOrder)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
    });

    it('never moves back, but fills in data that is still missing', function () {
        // The processing webhook arrived (matched by local_id) before the create response.
        $generation = heldGeneration($this->user, 'processing', [
            'pixelforge_id' => null,
            'provider_created_at' => null,
        ]);

        $outcome = ($this->apply)($generation, snapshotFor($generation, 'queued', [
            'id' => 'gen_late1',
            'created_at' => '2026-11-17T11:59:00Z',
        ]), 'submit');

        $generation->refresh();
        expect($outcome)->toBe(DeliveryOutcome::OutOfOrder)
            ->and($generation->status)->toBe(GenerationStatus::Processing)
            ->and($generation->pixelforge_id)->toBe('gen_late1')
            ->and(isoUtc($generation->provider_created_at))->toBe('2026-11-17T11:59:00Z');
    });
});

describe('success', function () {
    it('stores usage and charges the WorkedExample price exactly', function () {
        $generation = heldGeneration($this->user, 'queued');

        $outcome = ($this->apply)($generation, snapshotFor($generation, 'succeeded', [
            'usage' => FakePixelforge::workedExampleUsage(),
            'completed_at' => '2026-11-16T23:59:59Z',
            'image_url' => 'https://cdn.pixelforge.test/images/a.png?sig=1',
            'image_url_expires_at' => '2026-11-17T23:59:59Z',
        ]), 'webhook');

        $generation->refresh();
        expect($outcome)->toBe(DeliveryOutcome::Applied)
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->input_text_tokens)->toBe(115)
            ->and($generation->input_image_tokens)->toBe(1530)
            ->and($generation->output_tokens)->toBe(6450)
            ->and($generation->reasoning_tokens)->toBe(8000)
            ->and((int) $generation->provider_cost)->toBe(373_875_000)
            ->and((int) $generation->charged)->toBe(467_343_750)
            // Billing day comes from Pixelforge's completed_at, not from when we heard of it.
            ->and(isoUtc($generation->completed_at))->toBe('2026-11-16T23:59:59Z')
            ->and($generation->image_url)->toBe('https://cdn.pixelforge.test/images/a.png?sig=1')
            ->and(isoUtc($generation->image_url_expires_at))->toBe('2026-11-17T23:59:59Z')
            ->and($generation->is_anomaly)->toBeFalse();

        expect(ledgerOf($generation))->toEqualCanonicalizing([
            ['type' => 'hold', 'amount' => -APPLY_HELD],
            ['type' => 'charge', 'amount' => -467_343_750],
            ['type' => 'hold_release', 'amount' => APPLY_HELD],
        ])->and($this->ledger->balance($this->user))->toBe($this->startingBalance - 467_343_750);
    });

    it('prices with the generation\'s own rates from Pixelforge', function () {
        $generation = heldGeneration($this->user, 'processing');
        $doubled = [
            'input_text_tokens' => '10.00',
            'input_image_tokens' => '20.00',
            'output_tokens' => '80.00',
            'reasoning_tokens' => '25.00',
        ];

        ($this->apply)($generation, snapshotFor($generation, 'succeeded', [
            'usage' => FakePixelforge::workedExampleUsage(),
            'rates' => $doubled,
        ]), 'webhook');

        $generation->refresh();
        expect($generation->rates)->toEqual($doubled)
            ->and((int) $generation->provider_cost)->toBe(747_750_000)
            ->and((int) $generation->charged)->toBe(934_687_500)
            // The actual price may exceed the hold; the user pays the actual price.
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance - 934_687_500);
    });

    it('charges exactly once when the success arrives again', function (string $secondSource) {
        $generation = heldGeneration($this->user, 'processing');
        $snapshot = snapshotFor($generation, 'succeeded');

        ($this->apply)($generation, $snapshot, 'webhook');
        $second = ($this->apply)($generation->fresh(), $snapshot, $secondSource);

        $charges = collect(ledgerOf($generation))->where('type', 'charge');
        expect($second)->toBe(DeliveryOutcome::OutOfOrder)
            ->and($charges)->toHaveCount(1)
            ->and(collect(ledgerOf($generation))->where('type', 'hold_release'))->toHaveCount(1)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance - FakePixelforge::WORKED_EXAMPLE_USER_PRICE);
    })->with(['webhook', 'poll', 'replay']);
});

describe('failure', function () {
    it('fails without charging and releases the hold in full (SC-005)', function (string $code) {
        $generation = heldGeneration($this->user, 'processing');

        $outcome = ($this->apply)($generation, snapshotFor($generation, 'failed', [
            'error' => ['code' => $code, 'message' => 'Raw provider message'],
        ]), 'webhook');

        $generation->refresh();
        expect($outcome)->toBe(DeliveryOutcome::Applied)
            ->and($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe($code)
            ->and($generation->error_message)->toBe('Raw provider message')
            ->and($generation->charged)->toBeNull()
            ->and($generation->provider_cost)->toBeNull()
            ->and(isoUtc($generation->completed_at))->not->toBeNull();

        expect(ledgerOf($generation))->toBe([
            ['type' => 'hold', 'amount' => -APPLY_HELD],
            ['type' => 'hold_release', 'amount' => APPLY_HELD],
        ])->and($this->ledger->balance($this->user))->toBe($this->startingBalance);
    })->with(['generation_failed', 'internal_error']);
});

describe('final states never change', function () {
    it('keeps a success when a later snapshot says otherwise', function (string $status) {
        $generation = heldGeneration($this->user, 'processing');
        ($this->apply)($generation, snapshotFor($generation, 'succeeded'), 'webhook');
        $entries = ledgerOf($generation);

        $outcome = ($this->apply)($generation->fresh(), snapshotFor($generation->fresh(), $status), 'webhook');

        $generation->refresh();
        expect($outcome)->toBe(DeliveryOutcome::OutOfOrder)
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and((int) $generation->charged)->toBe(FakePixelforge::WORKED_EXAMPLE_USER_PRICE)
            ->and(ledgerOf($generation))->toBe($entries);
    })->with(['queued', 'processing', 'failed']);

    it('keeps a failure when a later snapshot says otherwise', function (string $status) {
        $generation = heldGeneration($this->user, 'processing');
        ($this->apply)($generation, snapshotFor($generation, 'failed'), 'webhook');
        $entries = ledgerOf($generation);

        $outcome = ($this->apply)($generation->fresh(), snapshotFor($generation->fresh(), $status), 'poll');

        expect($outcome)->toBe(DeliveryOutcome::OutOfOrder)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Failed)
            ->and(ledgerOf($generation))->toBe($entries)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance);
    })->with(['queued', 'processing', 'failed']);
});

describe('anomaly', function () {
    it('records the cost of a success after a local failure, without charging', function () {
        // We failed it ourselves (waited too long) and released the hold; then Pixelforge
        // reports a success. Reconciliation needs the cost; the user pays nothing.
        $generation = heldGeneration($this->user, 'failed', ['error_code' => 'waited_too_long']);
        $this->ledger->release($generation);

        $outcome = ($this->apply)($generation->fresh(), snapshotFor($generation, 'succeeded', [
            'usage' => FakePixelforge::workedExampleUsage(),
            'completed_at' => '2026-11-17T11:58:00Z',
        ]), 'webhook');

        $generation->refresh();
        expect($outcome)->toBe(DeliveryOutcome::OutOfOrder)
            ->and($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->is_anomaly)->toBeTrue()
            ->and((int) $generation->provider_cost)->toBe(373_875_000)
            ->and($generation->output_tokens)->toBe(6450)
            ->and(isoUtc($generation->completed_at))->toBe('2026-11-17T11:58:00Z')
            ->and($generation->charged)->toBeNull()
            ->and(collect(ledgerOf($generation))->where('type', 'charge'))->toBeEmpty()
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance);
    });
});

describe('timeline', function () {
    it('writes timeline events that record where the snapshot came from', function (string $source) {
        $generation = heldGeneration($this->user, 'submitting');

        ($this->apply)($generation, snapshotFor($generation, 'queued'), $source);

        $events = GenerationEvent::query()->where('generation_id', $generation->id)->get();
        expect($events)->not->toBeEmpty();

        foreach ($events as $event) {
            expect($event->message)->toBeString()->not->toBeEmpty()
                ->and($event->data['source'] ?? null)->toBe($source);
        }
    })->with(['submit', 'webhook', 'poll', 'replay']);

    it('adds events for a success', function () {
        $generation = heldGeneration($this->user, 'processing');
        $before = GenerationEvent::query()->where('generation_id', $generation->id)->count();

        ($this->apply)($generation, snapshotFor($generation, 'succeeded'), 'webhook');

        expect(GenerationEvent::query()->where('generation_id', $generation->id)->count())->toBeGreaterThan($before);
    });
});

describe('domain events', function () {
    beforeEach(fn () => Event::fake([GenerationSucceeded::class, GenerationFinished::class]));

    it('fires nothing for a non-final move', function () {
        $generation = heldGeneration($this->user, 'submitting');

        ($this->apply)($generation, snapshotFor($generation, 'processing'), 'webhook');

        Event::assertNotDispatched(GenerationSucceeded::class);
        Event::assertNotDispatched(GenerationFinished::class);
    });

    it('fires GenerationSucceeded and GenerationFinished once for a success delivered twice', function () {
        $generation = heldGeneration($this->user, 'processing');
        $snapshot = snapshotFor($generation, 'succeeded');

        ($this->apply)($generation, $snapshot, 'webhook');
        ($this->apply)($generation->fresh(), $snapshot, 'webhook');

        Event::assertDispatchedTimes(GenerationSucceeded::class, 1);
        Event::assertDispatchedTimes(GenerationFinished::class, 1);
        Event::assertDispatched(GenerationFinished::class, fn (GenerationFinished $event) => $event->generation->is($generation));
    });

    it('fires only GenerationFinished, once, for a failure delivered twice', function () {
        $generation = heldGeneration($this->user, 'processing');
        $snapshot = snapshotFor($generation, 'failed');

        ($this->apply)($generation, $snapshot, 'webhook');
        ($this->apply)($generation->fresh(), $snapshot, 'poll');

        Event::assertNotDispatched(GenerationSucceeded::class);
        Event::assertDispatchedTimes(GenerationFinished::class, 1);
    });

    it('fires nothing for an anomaly', function () {
        $generation = heldGeneration($this->user, 'failed', ['error_code' => 'waited_too_long']);
        $this->ledger->release($generation);

        ($this->apply)($generation->fresh(), snapshotFor($generation, 'succeeded'), 'webhook');

        Event::assertNotDispatched(GenerationSucceeded::class);
        Event::assertNotDispatched(GenerationFinished::class);
    });
});
