<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recent webhook deliveries, newest first (FR-019). Payloads and headers stay on the
 * server; the page shows what arrived and what we did with it.
 */
class DeliveriesController extends Controller
{
    private const PER_PAGE = 50;

    public function __invoke(): Response
    {
        $deliveries = WebhookDelivery::query()
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return Inertia::render('admin/Deliveries', [
            'deliveries' => [
                'data' => $deliveries->getCollection()->map(fn (WebhookDelivery $delivery) => [
                    'event_id' => $delivery->event_id,
                    'event_type' => $delivery->event_type,
                    'attempt' => $delivery->delivery_attempt,
                    'outcome' => $delivery->outcome->value,
                    'generation_id' => $delivery->generation_id,
                    'received_at' => $delivery->received_at->toIso8601String(),
                ])->all(),
                'next_cursor' => $deliveries->nextCursor()?->encode(),
            ],
        ]);
    }
}
