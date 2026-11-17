<?php

namespace App\Http\Controllers;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Enums\DeliveryOutcome;
use App\Models\Generation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * POST /webhooks/pixelforge (spec FR-009, contracts/http.md).
 *
 * 1. Verify the signature on the raw body before anything else.
 * 2. Record the delivery outside the processing transaction, so it survives a rollback.
 * 3. In one transaction: insert the event id (the dedupe) and apply the snapshot.
 *
 * A failure in step 3 rolls both back and answers 500, so Pixelforge redelivers.
 */
class PixelforgeWebhookController extends Controller
{
    /** The only headers we keep; anyone can post junk here, so nothing else is stored. */
    private const STORED_HEADERS = [
        'content-type',
        'user-agent',
        'pixelforge-event-id',
        'pixelforge-event-type',
        'pixelforge-delivery-attempt',
        'pixelforge-signature',
    ];

    public function __invoke(Request $request, WebhookSignature $signature, ApplyGenerationSnapshot $apply): Response
    {
        $body = $request->getContent();
        $signatureValid = $signature->verify((string) $request->header('Pixelforge-Signature', ''), $body, now()->getTimestamp());
        $event = $signatureValid ? json_decode($body, true) : null;

        $delivery = WebhookDelivery::query()->create([
            'event_id' => is_array($event) ? ($event['id'] ?? null) : $this->headerValue($request, 'Pixelforge-Event-Id'),
            'event_type' => is_array($event) ? ($event['type'] ?? null) : $this->headerValue($request, 'Pixelforge-Event-Type'),
            'delivery_attempt' => is_numeric($request->header('Pixelforge-Delivery-Attempt')) ? (int) $request->header('Pixelforge-Delivery-Attempt') : null,
            'signature_valid' => $signatureValid,
            // Pessimistic until processing says otherwise: a crash leaves "error" behind.
            'outcome' => $signatureValid ? DeliveryOutcome::Error : DeliveryOutcome::Rejected,
            'headers' => $this->storedHeaders($request),
            'payload' => is_array($event) ? $event : null,
            'received_at' => now(),
        ]);

        if (! $signatureValid) {
            return response()->noContent(400);
        }

        try {
            [$outcome, $generation] = $this->process($delivery, is_array($event) ? $event : [], $apply);
        } catch (Throwable $e) {
            report($e);

            return response()->noContent(500);
        }

        $delivery->update(['outcome' => $outcome, 'generation_id' => $generation?->id]);

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: DeliveryOutcome, 1: Generation|null}
     */
    private function process(WebhookDelivery $delivery, array $event, ApplyGenerationSnapshot $apply): array
    {
        if (($event['type'] ?? null) === 'ping') {
            return [DeliveryOutcome::Ping, null];
        }

        if (! is_string($event['id'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            throw new \InvalidArgumentException('Signed webhook without an event id or generation object.');
        }

        $snapshot = GenerationSnapshot::fromArray($event['data']['object']);

        $result = DB::transaction(function () use ($event, $snapshot, $apply) {
            // Lock the generation before inserting the event. The event's foreign key takes a
            // share lock on the generation row; two deliveries holding that share lock while
            // both waiting for the update lock in ApplyGenerationSnapshot would deadlock.
            $generation = $this->findAndLockGeneration($snapshot);

            // The primary key is the dedupe: a concurrent insert of the same id waits for
            // the other transaction, then inserts nothing.
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => (string) $event['type'],
                'generation_id' => $generation?->id,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return [DeliveryOutcome::Duplicate, $this->generationOfStoredEvent($event['id'])];
            }

            if ($generation === null) {
                return [DeliveryOutcome::UnknownGeneration, null];
            }

            $outcome = $apply($generation, $snapshot, 'webhook');

            if ($outcome === DeliveryOutcome::OutOfOrder) {
                $generation->recordEvent('late_ignored', 'Arrived late, ignored', [
                    'event_id' => $event['id'],
                    'status' => $snapshot->status->value,
                ]);
            }

            return [$outcome, $generation];
        });

        if ($result[0] === DeliveryOutcome::Duplicate && $result[1] !== null) {
            $result[1]->recordEvent('duplicate_ignored', 'Delivered again, ignored', [
                'event_id' => $event['id'],
                'delivery_attempt' => $delivery->delivery_attempt,
            ]);
        }

        return $result;
    }

    /**
     * Our id travels as metadata.local_id, so an event can be matched even before the
     * create request has returned; the Pixelforge id covers anything without it.
     */
    private function findAndLockGeneration(GenerationSnapshot $snapshot): ?Generation
    {
        if ($snapshot->localId !== null && Str::isUuid($snapshot->localId)) {
            $generation = Generation::query()->whereKey($snapshot->localId)->lockForUpdate()->first();

            if ($generation !== null) {
                return $generation;
            }
        }

        return Generation::query()->where('pixelforge_id', $snapshot->id)->lockForUpdate()->first();
    }

    private function generationOfStoredEvent(string $eventId): ?Generation
    {
        return WebhookEvent::query()->find($eventId)?->generation;
    }

    /**
     * @return array<string, string>
     */
    private function storedHeaders(Request $request): array
    {
        $headers = [];

        foreach (self::STORED_HEADERS as $name) {
            $value = $this->headerValue($request, $name);

            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function headerValue(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        return is_string($value) && $value !== '' ? Str::limit($value, 255, '') : null;
    }
}
