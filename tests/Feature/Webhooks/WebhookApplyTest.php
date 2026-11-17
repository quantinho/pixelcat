<?php

use App\Billing\Ledger;
use App\Enums\DeliveryOutcome;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Events\GenerationAccepted;
use App\Events\GenerationFinished;
use App\Events\GenerationSucceeded;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePixelforge;

/**
 * The webhook happy path (FR-009, US1): a signed event is applied once, in the request,
 * and answered 204.
 */
beforeEach(function () {
    // Follow-up jobs (image copy, next dispatch) are other tests' business.
    Event::fake([GenerationAccepted::class, GenerationSucceeded::class, GenerationFinished::class]);

    $this->ledger = app(Ledger::class);
    $this->user = User::factory()->create();
    $this->generation = Generation::factory()->for($this->user)->processing()->create(['estimate_max' => 500_000_000]);
    $this->ledger->hold($this->generation, 500_000_000);
});

function postWebhookForApply(array $webhook): TestResponse
{
    return test()->call('POST', '/webhooks/pixelforge', server: $webhook['server'], content: $webhook['body']);
}

it('charges a succeeded generation exactly once', function () {
    $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded'));

    postWebhookForApply($webhook)->assertNoContent();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost)->toBe(FakePixelforge::WORKED_EXAMPLE_PROVIDER_COST)
        ->and($generation->charged)->toBe(FakePixelforge::WORKED_EXAMPLE_USER_PRICE)
        ->and(LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerEntryType::Charge)->sum('amount'))
        ->toEqual(-FakePixelforge::WORKED_EXAMPLE_USER_PRICE)
        ->and(LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerEntryType::HoldRelease)->count())->toBe(1);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->outcome)->toBe(DeliveryOutcome::Applied)
        ->and($delivery->signature_valid)->toBeTrue()
        ->and($delivery->event_id)->toBe($webhook['event']['id'])
        ->and($delivery->event_type)->toBe('generation.succeeded')
        ->and($delivery->delivery_attempt)->toBe(1)
        ->and($delivery->generation_id)->toBe($generation->id)
        ->and($delivery->payload)->toEqual($webhook['event']);

    $event = WebhookEvent::query()->sole();
    expect($event->event_id)->toBe($webhook['event']['id'])
        ->and($event->type)->toBe('generation.succeeded')
        ->and($event->generation_id)->toBe($generation->id)
        ->and($event->payload)->toEqual($webhook['event']);
});

it('leaves the balance where it was before submitting when the generation fails', function () {
    $balanceBeforeSubmitting = $this->ledger->balance($this->user) + 500_000_000;
    $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'failed', ['error_code' => 'generation_failed']));

    postWebhookForApply($webhook)->assertNoContent();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->charged)->toBeNull()
        ->and($this->ledger->balance($this->user))->toBe($balanceBeforeSubmitting)
        ->and(LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerEntryType::Charge)->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->sole()->outcome)->toBe(DeliveryOutcome::Applied);
});

it('moves a queued generation to processing', function () {
    $generation = Generation::factory()->for($this->user)->queued()->create();
    $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($generation, 'processing'));

    postWebhookForApply($webhook)->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and(WebhookDelivery::query()->sole()->outcome)->toBe(DeliveryOutcome::Applied);
});
