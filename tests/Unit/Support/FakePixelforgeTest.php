<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePixelforge;

/*
 * Checks that the test double behaves like the published Pixelforge API, so tests built on it
 * mean something.
 */

beforeEach(function () {
    $this->pixelforge = FakePixelforge::fake();
    $this->api = fn (array $headers = []): PendingRequest => Http::baseUrl(config('pixelforge.base_url'))
        ->withToken(config('pixelforge.api_key'))
        ->withHeaders($headers)
        ->acceptJson();
});

it('blocks requests to anything else', function () {
    expect(fn () => Http::get('https://example.com/'))->toThrow(StrayRequestException::class);
});

it('serves pricing without authentication', function () {
    $response = Http::get(config('pixelforge.base_url').'/pricing');

    expect($response->status())->toBe(200)
        ->and($response->json('rates'))->toBe(FakePixelforge::RATES)
        ->and($response->json('tokens.output.high.1536x1024'))->toBe(6450)
        ->and($response->json('tokens.reasoning.medium'))->toBe(['min' => 200, 'max' => 2000]);
});

it('rejects a request without the API key', function () {
    $response = Http::get(config('pixelforge.base_url').'/generations/gen_x');

    expect($response->status())->toBe(401)->and($response->json('error.code'))->toBe('invalid_api_key');
});

it('accepts a create with 202, a queued generation and rate-limit headers', function () {
    $response = ($this->api)(['Idempotency-Key' => 'key-1'])->post('/generations', [
        'prompt' => 'a cat',
        'size' => '1536x1024',
        'quality' => 'high',
        'metadata' => ['local_id' => 'key-1'],
    ]);

    expect($response->status())->toBe(202)
        ->and($response->json('status'))->toBe('queued')
        ->and($response->json('id'))->toStartWith('gen_')
        ->and($response->json('metadata.local_id'))->toBe('key-1')
        ->and($response->json('rates'))->toBe(FakePixelforge::RATES)
        ->and($response->header('X-RateLimit-Remaining'))->toBe('59')
        ->and($this->pixelforge->createCalls())->toBe(1);
});

it('replays the same Idempotency-Key and refuses it with other parameters', function () {
    $first = ($this->api)(['Idempotency-Key' => 'key-2'])->post('/generations', ['prompt' => 'a cat']);
    $replay = ($this->api)(['Idempotency-Key' => 'key-2'])->post('/generations', ['prompt' => 'a cat']);
    $reused = ($this->api)(['Idempotency-Key' => 'key-2'])->post('/generations', ['prompt' => 'a dog']);

    expect($replay->status())->toBe(202)
        ->and($replay->json('id'))->toBe($first->json('id'))
        ->and($replay->header('Idempotent-Replayed'))->toBe('true')
        ->and($reused->status())->toBe(409)
        ->and($reused->json('error.code'))->toBe('idempotency_key_reused');
});

it('rejects unknown parameters with 422', function () {
    $response = ($this->api)()->post('/generations', ['prompt' => 'a cat', 'style' => 'noir']);

    expect($response->status())->toBe(422)
        ->and($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.param'))->not->toBeNull();
});

it('answers scripted errors with the documented status and Retry-After', function (string|array $outcome, int $status, string $code, ?string $retryAfter) {
    $this->pixelforge->createReturns($outcome);

    $response = ($this->api)()->post('/generations', ['prompt' => 'a cat']);

    expect($response->status())->toBe($status)
        ->and($response->json('error.code'))->toBe($code)
        ->and($response->json('error.type'))->toBeString()
        ->and($response->header('Retry-After') ?: null)->toBe($retryAfter);
})->with([
    'content policy' => ['content_policy_violation', 400, 'content_policy_violation', null],
    'invalid key' => ['invalid_api_key', 401, 'invalid_api_key', null],
    'rate limit' => ['rate_limit_exceeded', 429, 'rate_limit_exceeded', '20'],
    'rate limit, custom Retry-After' => [FakePixelforge::outcome('rate_limit_exceeded', retryAfter: 45), 429, 'rate_limit_exceeded', '45'],
    'concurrency' => ['concurrency_limit_exceeded', 429, 'concurrency_limit_exceeded', '5'],
    'overloaded' => ['overloaded', 503, 'overloaded', '2'],
    'unexpected 500' => [FakePixelforge::SERVER_ERROR, 500, 'internal_error', null],
]);

it('sends Retry-After until UTC midnight for the daily limit', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 23:00:00', 'UTC'));
    $this->pixelforge->createReturns('daily_limit_exceeded');

    $response = ($this->api)()->post('/generations', ['prompt' => 'a cat']);

    expect($response->status())->toBe(429)->and($response->header('Retry-After'))->toBe('3600');
});

it('times out without creating anything', function () {
    $this->pixelforge->createReturns(FakePixelforge::TIMEOUT);

    expect(fn () => ($this->api)(['Idempotency-Key' => 'key-3'])->post('/generations', ['prompt' => 'a cat', 'metadata' => ['local_id' => 'key-3']]))
        ->toThrow(ConnectionException::class);

    expect($this->pixelforge->storedFor('key-3'))->toBeNull();
});

it('can lose the response after creating, and replays that generation on retry', function () {
    $this->pixelforge->createReturns(FakePixelforge::TIMEOUT_AFTER_CREATE, 'overloaded');
    $params = ['prompt' => 'a cat', 'metadata' => ['local_id' => 'key-4']];

    expect(fn () => ($this->api)(['Idempotency-Key' => 'key-4'])->post('/generations', $params))
        ->toThrow(ConnectionException::class);

    $created = $this->pixelforge->storedFor('key-4');
    // Replays come before limits: the scripted 503 is not used for this key.
    $retry = ($this->api)(['Idempotency-Key' => 'key-4'])->post('/generations', $params);

    expect($created)->not->toBeNull()
        ->and($retry->status())->toBe(202)
        ->and($retry->json('id'))->toBe($created['id'])
        ->and($retry->header('Idempotent-Replayed'))->toBe('true');
});

it('serves stored generations and advances them', function () {
    $created = ($this->api)()->post('/generations', ['prompt' => 'a cat'])->json();

    $this->pixelforge->advance($created['id'], 'succeeded');
    $response = ($this->api)()->get("/generations/{$created['id']}");

    expect($response->json('status'))->toBe('succeeded')
        ->and($response->json('usage.output_tokens'))->toBe(6450)
        ->and($response->json('usage.total_tokens'))->toBe(16095)
        ->and($response->json('image_url'))->toStartWith('https://')
        ->and($response->json('completed_at'))->not->toBeNull()
        ->and($response->json('started_at'))->not->toBeNull()
        ->and($response->json('error'))->toBeNull()
        ->and(($this->api)()->get('/generations/gen_unknown')->status())->toBe(404);
});

it('serves scripted GET outcomes', function () {
    $this->pixelforge->getReturns('not_found', FakePixelforge::generation('processing', ['id' => 'gen_s']));

    expect(($this->api)()->get('/generations/gen_s')->status())->toBe(404)
        ->and(($this->api)()->get('/generations/gen_s')->json('status'))->toBe('processing');
});

it('reports usage per UTC day with exact decimal costs', function () {
    $this->pixelforge->withUsage([
        '2026-11-15' => ['generations' => 2, 'cost_usd' => '0.373875000'],
        '2026-11-16' => ['generations' => 1, 'cost_usd' => '0.000575001'],
    ]);

    $response = ($this->api)()->get('/usage', ['start_date' => '2026-11-14', 'end_date' => '2026-11-16']);

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('data.0'))->toMatchArray(['date' => '2026-11-14', 'generations' => 0, 'cost_usd' => '0.000000000'])
        ->and($response->json('data.1.cost_usd'))->toBe('0.373875000')
        ->and($response->json('total.generations'))->toBe(3)
        ->and($response->json('total.cost_usd'))->toBe('0.374450001')
        ->and(($this->api)()->get('/usage', ['start_date' => '2026-01-01', 'end_date' => '2026-11-16'])->json('error.code'))
        ->toBe('date_range_too_long');
});

describe('payload builders', function () {
    it('builds generation objects consistent with their status', function () {
        $queued = FakePixelforge::generation();
        $failed = FakePixelforge::generation('failed', ['error_code' => 'internal_error']);
        $succeeded = FakePixelforge::generation('succeeded');

        expect($queued)->toMatchArray(['object' => 'generation', 'status' => 'queued', 'usage' => null, 'completed_at' => null])
            ->and($failed['error'])->toMatchArray(['code' => 'internal_error'])
            ->and($failed['usage'])->toBeNull()
            ->and($succeeded['usage'])->toBe(FakePixelforge::workedExampleUsage())
            ->and($succeeded['image_url_expires_at'])->not->toBeNull();
    });

    it('builds webhook headers and body as Pixelforge sends them', function () {
        $webhook = FakePixelforge::webhook('generation.succeeded', ['id' => 'gen_w'], [
            'event_id' => 'evt_1', 'attempt' => 3, 'signature' => 't=1,v1=abc',
        ]);

        expect(json_decode($webhook['body'], true))->toMatchArray(['id' => 'evt_1', 'object' => 'event', 'type' => 'generation.succeeded'])
            ->and($webhook['event']['data']['object']['id'])->toBe('gen_w')
            ->and($webhook['headers'])->toMatchArray([
                'Pixelforge-Event-Id' => 'evt_1',
                'Pixelforge-Event-Type' => 'generation.succeeded',
                'Pixelforge-Delivery-Attempt' => '3',
                'Pixelforge-Signature' => 't=1,v1=abc',
            ])
            ->and($webhook['server'])->toMatchArray([
                'HTTP_PIXELFORGE_EVENT_ID' => 'evt_1',
                'HTTP_PIXELFORGE_SIGNATURE' => 't=1,v1=abc',
                'CONTENT_TYPE' => 'application/json',
            ]);
    });

    it('signs webhooks with the HMAC of "{t}.{raw body}"', function () {
        $webhook = FakePixelforge::webhook('ping', [], ['timestamp' => 1_795_000_000]);

        expect($webhook['headers']['Pixelforge-Signature'])
            ->toBe('t=1795000000,v1='.hash_hmac('sha256', '1795000000.'.$webhook['body'], FakePixelforge::WEBHOOK_SECRET));
    });
});
