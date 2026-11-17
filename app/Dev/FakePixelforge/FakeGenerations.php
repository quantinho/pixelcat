<?php

namespace App\Dev\FakePixelforge;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * DEV ONLY. State and documented numbers for the local fake Pixelforge, kept in the cache so
 * it survives between requests and queue jobs. Mirrors tests/Support/FakePixelforge.php,
 * which encodes the API docs; the app never depends on the tests namespace.
 */
final class FakeGenerations
{
    /** USD per 1M tokens, as in the docs. */
    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** Output tokens by quality, then size. */
    public const OUTPUT_TOKENS = [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
        'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
    ];

    /** Reasoning token range by quality. */
    public const REASONING_TOKENS = [
        'low' => ['min' => 0, 'max' => 500],
        'medium' => ['min' => 200, 'max' => 2000],
        'high' => ['min' => 1000, 'max' => 8000],
    ];

    public const MAX_ACTIVE = 3;

    private const PREFIX = 'fake-pixelforge:';

    /** @return array<string, mixed> GET /v1/pricing */
    public static function pricing(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => self::OUTPUT_TOKENS,
                'reasoning' => self::REASONING_TOKENS,
            ],
        ];
    }

    /**
     * A new queued generation for validated create parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        $generation = [
            'id' => 'gen_'.Str::lower(Str::random(24)),
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => $params['prompt'],
            'size' => $params['size'] ?? '1024x1024',
            'quality' => $params['quality'] ?? 'medium',
            'reference_images' => $params['reference_images'] ?? [],
            'metadata' => $params['metadata'] ?? (object) [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::RATES,
            'error' => null,
            'created_at' => self::time(CarbonImmutable::now()),
            'started_at' => null,
            'completed_at' => null,
        ];

        $this->save($generation);
        Cache::forever(self::PREFIX.'ids', [...$this->ids(), $generation['id']]);

        return $generation;
    }

    /**
     * Move a generation forward, filling what the new status implies.
     *
     * @return array<string, mixed>
     */
    public function advance(string $id, string $status): array
    {
        $generation = $this->find($id) ?? throw new \InvalidArgumentException("No fake generation [{$id}].");
        $now = CarbonImmutable::now();
        $generation['status'] = $status;

        if ($status === 'processing') {
            $generation['started_at'] = self::time($now);
        }

        if ($status === 'succeeded') {
            $generation['completed_at'] = self::time($now);
            $generation['usage'] = $this->usageFor($generation);
            $generation['image_url'] = url("/_fake/pixelforge/v1/images/{$id}.png");
            $generation['image_url_expires_at'] = self::time($now->addDay());
        }

        if ($status === 'failed') {
            $generation['completed_at'] = self::time($now);
            $generation['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image for this prompt.'];
        }

        $this->save($generation);

        return $generation;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $generation = Cache::get(self::PREFIX.'generation:'.$id);

        return is_array($generation) ? $generation : null;
    }

    /** @return list<array<string, mixed>> newest first */
    public function all(): array
    {
        $generations = [];
        foreach (array_reverse($this->ids()) as $id) {
            $generation = $this->find($id);
            if ($generation !== null) {
                $generations[] = $generation;
            }
        }

        return $generations;
    }

    public function activeCount(): int
    {
        return count(array_filter($this->all(), fn (array $g) => in_array($g['status'], ['queued', 'processing'], true)));
    }

    /** @return array{hash: string, id: string}|null */
    public function idempotencyKey(string $key): ?array
    {
        $entry = Cache::get(self::PREFIX.'key:'.$key);

        return is_array($entry) && is_string($entry['hash'] ?? null) && is_string($entry['id'] ?? null)
            ? ['hash' => $entry['hash'], 'id' => $entry['id']]
            : null;
    }

    public function rememberIdempotencyKey(string $key, string $hash, string $id): void
    {
        Cache::forever(self::PREFIX.'key:'.$key, ['hash' => $hash, 'id' => $id]);
    }

    /**
     * Token usage within the documented ranges.
     *
     * @param  array<string, mixed>  $generation
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}
     */
    private function usageFor(array $generation): array
    {
        $quality = (string) $generation['quality'];
        $estimate = max(1, (int) ceil(mb_strlen((string) $generation['prompt']) / 4));
        $text = random_int(max(1, intdiv($estimate * 85, 100)), max(1, intdiv($estimate * 115, 100)));
        $image = 765 * count((array) $generation['reference_images']);
        $output = self::OUTPUT_TOKENS[$quality][(string) $generation['size']];
        $range = self::reasoningRange($quality);
        $reasoning = random_int($range['min'], $range['max']);

        return [
            'input_text_tokens' => $text,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'total_tokens' => $text + $image + $output + $reasoning,
        ];
    }

    /**
     * The usage of a succeeded generation, as CalculateCost takes it.
     *
     * @param  array<string, mixed>  $generation
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    public static function usageOf(array $generation): array
    {
        $usage = is_array($generation['usage'] ?? null) ? $generation['usage'] : [];

        return [
            'input_text_tokens' => (int) ($usage['input_text_tokens'] ?? 0),
            'input_image_tokens' => (int) ($usage['input_image_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
        ];
    }

    /** @return array{min: int, max: int} */
    private static function reasoningRange(string $quality): array
    {
        return self::REASONING_TOKENS[$quality] ?? throw new \InvalidArgumentException("Unknown quality [{$quality}].");
    }

    /** @param array<string, mixed> $generation */
    private function save(array $generation): void
    {
        Cache::forever(self::PREFIX.'generation:'.$generation['id'], $generation);
    }

    /** @return list<string> */
    private function ids(): array
    {
        $ids = Cache::get(self::PREFIX.'ids', []);

        return is_array($ids) ? array_values($ids) : [];
    }

    private static function time(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
