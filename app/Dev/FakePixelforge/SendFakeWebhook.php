<?php

namespace App\Dev\FakePixelforge;

use App\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * DEV ONLY. Posts one signed webhook to this app's /webhooks/pixelforge, exactly as Pixelforge
 * would: same headers, HMAC-SHA256 of "t.body" with PIXELFORGE_WEBHOOK_SECRET, and retries
 * on anything but a 2xx (up to 6 attempts).
 */
class SendFakeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300];

    /**
     * @param  array<string, mixed>  $object  the generation object (data.object)
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public array $object,
    ) {}

    public function handle(): void
    {
        $body = (string) json_encode([
            'id' => $this->eventId,
            'object' => 'event',
            'type' => $this->type,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'data' => ['object' => $this->object],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::withHeaders([
            'User-Agent' => 'Pixelforge-Webhooks/1.0',
            'Pixelforge-Event-Id' => $this->eventId,
            'Pixelforge-Event-Type' => $this->type,
            'Pixelforge-Delivery-Attempt' => (string) $this->attempts(),
            'Pixelforge-Signature' => WebhookSignature::sign($body, (string) config('pixelforge.webhook_secret'), CarbonImmutable::now()->getTimestamp()),
        ])
            ->withBody($body, 'application/json')
            ->timeout(10)
            ->withoutRedirecting()
            ->post(url('/webhooks/pixelforge'))
            ->throw();
    }
}
