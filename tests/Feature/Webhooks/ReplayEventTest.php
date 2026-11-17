<?php

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Events\GenerationFinished;
use App\Events\GenerationSucceeded;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakePixelforge;

/**
 * pixelforge:replay-event {event_id} (FR-021) re-applies a stored event through the one
 * snapshot writer.
 */
beforeEach(function () {
    Event::fake([GenerationSucceeded::class, GenerationFinished::class]);
    $this->generation = Generation::factory()->for(User::factory())->processing()->create(['estimate_max' => 500_000_000]);
    app(Ledger::class)->hold($this->generation, 500_000_000);
});

/** @param array<string, mixed> $object */
function storeEvent(array $object, ?Generation $generation): WebhookEvent
{
    $event = FakePixelforge::webhookFor($object)['event'];

    return WebhookEvent::query()->create([
        'event_id' => $event['id'],
        'type' => $event['type'],
        'generation_id' => $generation?->id,
        'payload' => $event,
        'processed_at' => now(),
    ]);
}

it('re-applies a stored event through ApplyGenerationSnapshot', function () {
    $event = storeEvent(FakePixelforge::generationFor($this->generation, 'succeeded'), $this->generation);

    $this->artisan('pixelforge:replay-event', ['event_id' => $event->event_id])
        ->expectsOutputToContain('applied')
        ->assertSuccessful();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged)->toBe(FakePixelforge::WORKED_EXAMPLE_USER_PRICE)
        ->and($generation->events()->where('type', 'succeeded')->sole()->data)->toMatchArray(['source' => 'replay']);
});

it('finds the generation from the payload when the event was stored before we knew it', function () {
    $event = storeEvent(FakePixelforge::generationFor($this->generation, 'succeeded'), null);

    $this->artisan('pixelforge:replay-event', ['event_id' => $event->event_id])->assertSuccessful();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('changes nothing when the event is already applied', function () {
    $event = storeEvent(FakePixelforge::generationFor($this->generation, 'succeeded'), $this->generation);
    $this->artisan('pixelforge:replay-event', ['event_id' => $event->event_id])->assertSuccessful();
    $before = $this->generation->fresh()->only(['status', 'charged', 'provider_cost']);

    $this->artisan('pixelforge:replay-event', ['event_id' => $event->event_id])
        ->expectsOutputToContain('out_of_order')
        ->assertSuccessful();

    expect($this->generation->fresh()->only(['status', 'charged', 'provider_cost']))->toEqual($before)
        ->and($this->generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count())->toBe(1);
});

it('fails with a clear message for an unknown event id', function () {
    $this->artisan('pixelforge:replay-event', ['event_id' => 'evt_missing'])
        ->expectsOutputToContain('No stored event [evt_missing]')
        ->assertFailed();
});

it('fails with a clear message when no generation matches the event', function () {
    $event = storeEvent(FakePixelforge::generation('succeeded', ['id' => 'gen_elsewhere']), null);

    $this->artisan('pixelforge:replay-event', ['event_id' => $event->event_id])
        ->expectsOutputToContain('No generation matches')
        ->assertFailed();
});
