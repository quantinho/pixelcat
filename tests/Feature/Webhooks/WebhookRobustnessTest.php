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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePixelforge;

/**
 * Webhooks arrive at least once, in any order, possibly forged (spec US2, Failure Modes).
 */
beforeEach(function () {
    Event::fake([GenerationAccepted::class, GenerationSucceeded::class, GenerationFinished::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->generation = Generation::factory()->for($this->user)->processing()->create(['estimate_max' => 500_000_000]);
    app(Ledger::class)->hold($this->generation, 500_000_000);
});

function postWebhook(array $webhook): TestResponse
{
    return test()->call('POST', '/webhooks/pixelforge', server: $webhook['server'], content: $webhook['body']);
}

function chargesOf(Generation $generation): int
{
    return LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerEntryType::Charge)->count();
}

/** @return list<string> */
function timelineOf(Generation $generation): array
{
    return $generation->events()->pluck('message')->all();
}

describe('signature', function () {
    it('rejects a delivery whose signature is wrong, keeping headers only', function (array $options) {
        $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded'), $options);

        postWebhook($webhook)->assertStatus(400);

        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->outcome)->toBe(DeliveryOutcome::Rejected)
            ->and($delivery->signature_valid)->toBeFalse()
            ->and($delivery->payload)->toBeNull()
            ->and($delivery->headers)->toHaveKey('pixelforge-event-id')
            ->and($delivery->event_id)->toBe($webhook['event']['id'])
            ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Processing)
            ->and(chargesOf($this->generation))->toBe(0)
            ->and(WebhookEvent::query()->count())->toBe(0);
    })->with([
        'other secret' => [['secret' => 'whsec_someoneelse']],
        'garbage header' => [['signature' => 'nonsense']],
        'empty header' => [['signature' => '']],
        // 1_794_916_800 is the frozen test time, 2026-11-17 12:00:00 UTC.
        'signed 301 s ago' => [['timestamp' => 1_794_916_800 - 301]],
        'signed 301 s ahead' => [['timestamp' => 1_794_916_800 + 301]],
    ]);

    it('accepts a signature at the edge of the 300 s window', function () {
        $webhook = FakePixelforge::webhookFor(
            FakePixelforge::generationFor($this->generation, 'succeeded'),
            ['timestamp' => CarbonImmutable::now()->getTimestamp() - 300],
        );

        postWebhook($webhook)->assertNoContent();
    });

    it('rejects a delivery with no signature header', function () {
        $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded'));
        unset($webhook['server']['HTTP_PIXELFORGE_SIGNATURE']);

        postWebhook($webhook)->assertStatus(400);

        expect(WebhookDelivery::query()->sole()->outcome)->toBe(DeliveryOutcome::Rejected);
    });

    it('rejects a body that was changed after signing', function () {
        $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded'));
        $webhook['body'] = str_replace('"succeeded"', '"failed"', $webhook['body']);

        postWebhook($webhook)->assertStatus(400);

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);
    });
});

describe('duplicates', function () {
    it('answers a redelivered event 2xx, logs it and changes nothing', function () {
        $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded'));
        postWebhook($webhook)->assertNoContent();
        $after = $this->generation->fresh()->only(['status', 'charged', 'provider_cost', 'last_synced_at']);

        $again = FakePixelforge::webhookFor($webhook['event']['data']['object'], ['event_id' => $webhook['event']['id'], 'attempt' => 2]);
        postWebhook($again)->assertNoContent();

        expect(chargesOf($this->generation))->toBe(1)
            ->and($this->generation->fresh()->only(['status', 'charged', 'provider_cost', 'last_synced_at']))->toEqual($after)
            ->and(WebhookDelivery::query()->orderBy('id')->pluck('outcome')->all())
            ->toBe([DeliveryOutcome::Applied, DeliveryOutcome::Duplicate])
            ->and(WebhookDelivery::query()->latest('id')->first()->delivery_attempt)->toBe(2)
            ->and(WebhookDelivery::query()->latest('id')->first()->generation_id)->toBe($this->generation->id)
            ->and(WebhookEvent::query()->count())->toBe(1)
            ->and(timelineOf($this->generation))->toContain('Delivered again, ignored');
    });

    it('charges once when the same success arrives under two event ids', function () {
        $object = FakePixelforge::generationFor($this->generation, 'succeeded');

        postWebhook(FakePixelforge::webhookFor($object))->assertNoContent();
        postWebhook(FakePixelforge::webhookFor($object))->assertNoContent();

        expect(chargesOf($this->generation))->toBe(1)
            ->and(WebhookDelivery::query()->orderBy('id')->pluck('outcome')->all())
            ->toBe([DeliveryOutcome::Applied, DeliveryOutcome::OutOfOrder]);
    });
});

describe('out of order', function () {
    it('logs a late processing event after success and keeps succeeded', function () {
        $processing = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'processing'));
        postWebhook(FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded')))->assertNoContent();

        postWebhook($processing)->assertNoContent();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and(chargesOf($this->generation))->toBe(1)
            ->and(WebhookDelivery::query()->latest('id')->first()->outcome)->toBe(DeliveryOutcome::OutOfOrder)
            ->and(timelineOf($this->generation))->toContain('Arrived late, ignored');
    });

    it('never revives a failed generation', function () {
        postWebhook(FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'failed')))->assertNoContent();

        postWebhook(FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'processing')))->assertNoContent();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Failed)
            ->and(chargesOf($this->generation))->toBe(0);
    });
});

describe('matching', function () {
    it('matches an event that arrives before the create request returned by metadata.local_id', function () {
        $generation = Generation::factory()->for($this->user)->submitting()->create(['pixelforge_id' => null, 'estimate_max' => 500_000_000]);
        app(Ledger::class)->hold($generation, 500_000_000);
        $object = FakePixelforge::generationFor($generation, 'succeeded', ['id' => 'gen_early1']);

        postWebhook(FakePixelforge::webhookFor($object))->assertNoContent();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->pixelforge_id)->toBe('gen_early1')
            ->and(chargesOf($generation))->toBe(1)
            ->and(WebhookDelivery::query()->sole()->generation_id)->toBe($generation->id);
    });

    it('matches by pixelforge_id when the event has no local id', function () {
        $object = FakePixelforge::generationFor($this->generation, 'succeeded', ['metadata' => []]);

        postWebhook(FakePixelforge::webhookFor($object))->assertNoContent();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('answers 2xx and ignores an event for a generation we do not know', function (array $metadata) {
        $object = FakePixelforge::generation('succeeded', ['id' => 'gen_elsewhere', 'metadata' => $metadata]);

        postWebhook(FakePixelforge::webhookFor($object))->assertNoContent();

        expect(WebhookDelivery::query()->sole()->outcome)->toBe(DeliveryOutcome::UnknownGeneration)
            ->and(WebhookDelivery::query()->sole()->generation_id)->toBeNull()
            ->and(LedgerEntry::query()->where('type', LedgerEntryType::Charge)->count())->toBe(0);
    })->with([
        'no local id' => [[]],
        'local id from another environment' => [['local_id' => '0199a1b2-0000-7000-8000-000000000000']],
        'local id that is not a uuid' => [['local_id' => 'order-42']],
    ]);
});

it('answers a ping 2xx and logs it', function () {
    postWebhook(FakePixelforge::webhook('ping', ['message' => 'Hello from Pixelforge']))->assertNoContent();

    expect(WebhookDelivery::query()->sole()->outcome)->toBe(DeliveryOutcome::Ping)
        ->and(WebhookEvent::query()->count())->toBe(0);
});

it('answers 500 when applying fails, keeps the delivery, and applies the redelivery', function () {
    $failOnce = true;
    Generation::saving(function () use (&$failOnce) {
        if ($failOnce) {
            $failOnce = false;

            throw new RuntimeException('database went away');
        }
    });
    $webhook = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded'));

    postWebhook($webhook)->assertStatus(500);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and(chargesOf($this->generation))->toBe(0)
        ->and(WebhookEvent::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->sole()->outcome)->toBe(DeliveryOutcome::Error)
        ->and(WebhookDelivery::query()->sole()->payload)->toEqual($webhook['event']);

    // Pixelforge retries the same event.
    $retry = FakePixelforge::webhookFor($webhook['event']['data']['object'], ['event_id' => $webhook['event']['id'], 'attempt' => 2]);
    postWebhook($retry)->assertNoContent();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(chargesOf($this->generation))->toBe(1)
        ->and(WebhookDelivery::query()->orderBy('id')->pluck('outcome')->all())
        ->toBe([DeliveryOutcome::Error, DeliveryOutcome::Applied]);
});
