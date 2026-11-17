<?php

namespace App\Pixelforge;

use App\Billing\Money;
use App\Models\Generation;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin client for the Pixelforge API. It turns every outcome into one of three things:
 * a value (success), PixelforgeRejected (a definite "nothing was created") or
 * PixelforgeUnavailable (we don't know what happened).
 */
class PixelforgeClient
{
    private const REMAINING_KEY = 'pixelforge:rate_limit_remaining';

    private const OVERLOADED_KEY = 'pixelforge:overloaded_at';

    private const PRICING_KEY = 'pixelforge:pricing';

    /** Statuses for which the docs guarantee nothing was created or billed. */
    private const DEFINITE_STATUSES = [400, 401, 404, 409, 422, 429, 503];

    public function pricing(): Pricing
    {
        /** @var array<string, mixed> $json */
        $json = Cache::remember(self::PRICING_KEY, 300, fn () => $this->send(fn (PendingRequest $http) => $http->get('pricing'))->json());

        return Pricing::fromArray($json);
    }

    public function createGeneration(Generation $generation): GenerationSnapshot
    {
        $body = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'metadata' => ['local_id' => $generation->id],
        ];

        // Unknown or empty parameters are not sent; Pixelforge rejects unknown ones.
        if ($generation->reference_images !== []) {
            $body['reference_images'] = $generation->reference_images;
        }

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('generations', $body));

        return $this->snapshot($response);
    }

    public function getGeneration(string $pixelforgeId): GenerationSnapshot
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('generations/'.rawurlencode($pixelforgeId)));

        return $this->snapshot($response);
    }

    /**
     * Provider cost per UTC completion day, as Pixelforge billed it for this API key.
     *
     * @return array<string, array{generations: int, cost: int}>
     */
    public function usage(CarbonInterface $from, CarbonInterface $to): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]));

        $days = [];
        foreach ($response->json('data', []) as $day) {
            $days[(string) $day['date']] = [
                'generations' => (int) $day['generations'],
                'cost' => Money::fromDecimal((string) $day['cost_usd']),
            ];
        }

        return $days;
    }

    public function remainingRequests(): ?int
    {
        $remaining = Cache::get(self::REMAINING_KEY);

        return $remaining === null ? null : (int) $remaining;
    }

    /**
     * True while Pixelforge is rate limiting us or was overloaded in the last 2 minutes.
     */
    public function isBusy(): bool
    {
        $remaining = $this->remainingRequests();

        return ($remaining !== null && $remaining < 10) || Cache::has(self::OVERLOADED_KEY);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request): Response
    {
        $http = Http::baseUrl(rtrim((string) config('pixelforge.base_url'), '/').'/')
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('pixelforge.timeout'));

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw new PixelforgeUnavailable('Pixelforge did not respond: '.$e->getMessage(), previous: $e);
        }

        $this->recordLimits($response);

        if ($response->successful()) {
            return $response;
        }

        $code = $response->json('error.code');

        if (in_array($response->status(), self::DEFINITE_STATUSES, true) && is_string($code)) {
            $retryAfter = $response->header('Retry-After');

            throw new PixelforgeRejected(
                errorCode: $code,
                httpStatus: $response->status(),
                retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
                message: (string) $response->json('error.message', ''),
            );
        }

        throw new PixelforgeUnavailable("Unexpected Pixelforge response: HTTP {$response->status()}.");
    }

    /**
     * A success we can't read is an unknown outcome, not an error of ours: for a create,
     * Pixelforge may still have made (and will bill) the generation.
     */
    private function snapshot(Response $response): GenerationSnapshot
    {
        $json = $response->json();

        try {
            return GenerationSnapshot::fromArray(is_array($json) ? $json : []);
        } catch (InvalidArgumentException $e) {
            throw new PixelforgeUnavailable("Unreadable Pixelforge response: HTTP {$response->status()}.", previous: $e);
        }
    }

    private function recordLimits(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');

        if (is_numeric($remaining)) {
            Cache::put(self::REMAINING_KEY, (int) $remaining, 60);
        }

        if ($response->status() === 503) {
            Cache::put(self::OVERLOADED_KEY, now()->toIso8601String(), 120);
        }
    }
}
