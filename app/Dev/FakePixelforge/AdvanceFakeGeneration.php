<?php

namespace App\Dev\FakePixelforge;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * DEV ONLY. The fake's simulator: moves a generation to its next status and has the webhook
 * for it delivered, honouring the prompt's test keywords.
 */
class AdvanceFakeGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'processing'|'succeeded'|'failed'  $status
     */
    public function __construct(public string $generationId, public string $status) {}

    public function handle(FakeGenerations $generations): void
    {
        $before = $generations->find($this->generationId);

        if ($before === null || in_array($before['status'], ['succeeded', 'failed'], true)) {
            return;
        }

        $prompt = mb_strtolower((string) $before['prompt']);
        $duplicate = str_contains($prompt, '[duplicate]');
        $generation = $generations->advance($this->generationId, $this->status);

        $this->deliver($generation, $duplicate);

        if ($this->status === 'processing') {
            self::dispatch($this->generationId, str_contains($prompt, '[fail]') ? 'failed' : 'succeeded')
                ->delay(str_contains($prompt, '[slow]') ? 40 : 5);
        } elseif ($duplicate && $before['status'] === 'processing') {
            // Any order, as the docs warn: the processing event once more, after the result.
            $this->deliver($before, false, delay: 4);
        }
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    private function deliver(array $generation, bool $twice, int $delay = 0): void
    {
        $eventId = 'evt_'.Str::lower(Str::random(24));
        $type = 'generation.'.$generation['status'];

        SendFakeWebhook::dispatch($eventId, $type, $generation)->delay($delay);

        if ($twice) {
            // At least once: the same event id delivered again.
            SendFakeWebhook::dispatch($eventId, $type, $generation)->delay($delay + 2);
        }
    }
}
