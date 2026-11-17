<?php

namespace Tests\Support;

use App\Models\Generation;
use App\Pixelforge\WebhookSignature;
use BackedEnum;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A Pixelforge double for tests, built from the published API docs
 * (https://pixelforge.promptedit.dev/docs), including its failure behaviour.
 *
 * Install it with `FakePixelforge::fake()`, then script what the next create requests return:
 *
 *     $pixelforge = FakePixelforge::fake()
 *         ->createReturns('overloaded', FakePixelforge::TIMEOUT, FakePixelforge::ACCEPTED);
 *
 * The static helpers build API payloads (pricing, generation objects, usage, errors) and signed
 * webhook requests without installing the fake.
 */
final class FakePixelforge
{
    public const WEBHOOK_SECRET = 'whsec_testsecret';

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

    /** The docs' worked example: 400-char prompt, high, 1536x1024, 2 references, maximum usage. */
    public const WORKED_EXAMPLE_USAGE = [
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ];

    public const WORKED_EXAMPLE_PROVIDER_COST = 373_875_000;

    public const WORKED_EXAMPLE_USER_PRICE = 467_343_750;

    // Create outcomes that are not Pixelforge error codes.
    public const ACCEPTED = 'accepted';

    /** The connection times out; nothing is created. */
    public const TIMEOUT = 'timeout';

    /** Pixelforge creates the generation, but the response is lost (timeout). */
    public const TIMEOUT_AFTER_CREATE = 'timeout_after_create';

    /** An unexpected 500: ambiguous, nothing is created. */
    public const SERVER_ERROR = 'server_error';

    /** @var array<string, array{0: int, 1: string, 2: string}> code => [status, type, message] */
    public const ERRORS = [
        'content_policy_violation' => [400, 'invalid_request_error', 'The prompt was rejected by the content policy.'],
        'invalid_api_key' => [401, 'authentication_error', 'The API key is missing, malformed or revoked.'],
        'not_found' => [404, 'invalid_request_error', 'No such generation.'],
        'idempotency_key_reused' => [409, 'idempotency_error', 'This Idempotency-Key was used with different parameters.'],
        'validation_failed' => [422, 'invalid_request_error', 'The request is invalid.'],
        'date_range_too_long' => [422, 'invalid_request_error', 'The date range may not exceed 92 days.'],
        'rate_limit_exceeded' => [429, 'rate_limit_error', 'Too many requests. Try again later.'],
        'concurrency_limit_exceeded' => [429, 'rate_limit_error', 'Too many generations in progress.'],
        'daily_limit_exceeded' => [429, 'rate_limit_error', 'The daily generation limit is reached.'],
        'overloaded' => [503, 'api_error', 'Pixelforge is overloaded. Try again shortly.'],
    ];

    /** Retry-After seconds sent with each code by default. */
    public const RETRY_AFTER = [
        'rate_limit_exceeded' => 20,
        'concurrency_limit_exceeded' => 5,
        'overloaded' => 2,
    ];

    private const CREATE_PARAMS = ['prompt', 'size', 'quality', 'reference_images', 'metadata'];

    /** @var list<string|array{code: string, retry_after?: int|null}> */
    private array $createOutcomes = [];

    /** @var list<string|array<string, mixed>> */
    private array $getOutcomes = [];

    /** @var array<string, array<string, mixed>> generation objects by Pixelforge id */
    private array $generations = [];

    /** @var array<string, array{hash: string, id: string}> */
    private array $idempotencyKeys = [];

    /** @var array<string, array<string, mixed>> */
    private array $usageDays = [];

    /** @var array<string, mixed> */
    private array $pricing;

    private int $rateLimitRemaining = 59;

    private int $createCalls = 0;

    private function __construct()
    {
        $this->pricing = self::pricingJson();
    }

    /** Route every request to the configured Pixelforge base URL through a new fake. */
    public static function fake(): self
    {
        $fake = new self;

        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    // ---------------------------------------------------------------------------------------
    // Scripting
    // ---------------------------------------------------------------------------------------

    /**
     * Outcomes for the next create requests, in order; once used up, requests are accepted.
     * Each is an error code from ERRORS, ACCEPTED, TIMEOUT, TIMEOUT_AFTER_CREATE, SERVER_ERROR,
     * or `FakePixelforge::outcome($code, retryAfter: 30)`.
     *
     * As in the real API, a request whose Idempotency-Key already created a generation is
     * replayed before any scripted outcome (and before limits).
     *
     * @param  string|array{code: string, retry_after?: int|null}  ...$outcomes
     */
    public function createReturns(string|array ...$outcomes): self
    {
        array_push($this->createOutcomes, ...$outcomes);

        return $this;
    }

    /**
     * Outcomes for the next GET /generations/{id} requests, in order: an error code, TIMEOUT,
     * SERVER_ERROR or a generation object. Once used up, the stored generation is returned.
     *
     * @param  string|array<string, mixed>  ...$outcomes
     */
    public function getReturns(string|array ...$outcomes): self
    {
        array_push($this->getOutcomes, ...$outcomes);

        return $this;
    }

    /** @return array{code: string, retry_after: int|null} */
    public static function outcome(string $code, ?int $retryAfter = null): array
    {
        return ['code' => $code, 'retry_after' => $retryAfter];
    }

    /** @param array<string, string> $rates */
    public function withRates(array $rates): self
    {
        $this->pricing = self::pricingJson($rates);

        return $this;
    }

    /** X-RateLimit-Remaining sent with every authenticated response. */
    public function withRateLimitRemaining(int $remaining): self
    {
        $this->rateLimitRemaining = $remaining;

        return $this;
    }

    /**
     * Usage rows for GET /v1/usage, keyed by UTC date. Days not given report zero usage.
     *
     * @param  array<string, array{generations?: int, cost_usd?: string, input_text_tokens?: int, input_image_tokens?: int, output_tokens?: int, reasoning_tokens?: int}>  $days
     */
    public function withUsage(array $days): self
    {
        foreach ($days as $date => $day) {
            $this->usageDays[$date] = $day;
        }

        return $this;
    }

    /**
     * Put a generation object in the fake's store (served by GET /generations/{id}).
     *
     * @param  array<string, mixed>  $generation
     * @return array<string, mixed>
     */
    public function store(array $generation): array
    {
        return $this->generations[$generation['id']] = $generation;
    }

    /**
     * Move a stored generation to another status, filling the fields Pixelforge fills, and
     * return the new object (use it for a GET response or a webhook).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function advance(string $pixelforgeId, string $status, array $overrides = []): array
    {
        $current = $this->generations[$pixelforgeId]
            ?? throw new \InvalidArgumentException("The fake has no generation [{$pixelforgeId}].");

        // Let generation() fill what the new status implies; keep a start time already set.
        foreach (['usage', 'image_url', 'image_url_expires_at', 'error', 'completed_at', 'started_at'] as $key) {
            if ($key !== 'started_at' || $current[$key] === null) {
                unset($current[$key]);
            }
        }

        return $this->store(self::generation($status, [...$current, ...$overrides]));
    }

    /** @return array<string, mixed>|null */
    public function stored(string $pixelforgeId): ?array
    {
        return $this->generations[$pixelforgeId] ?? null;
    }

    /** @return array<string, mixed>|null the generation created for one of our generation ids */
    public function storedFor(string $localId): ?array
    {
        foreach ($this->generations as $generation) {
            if (($generation['metadata']['local_id'] ?? null) === $localId) {
                return $generation;
            }
        }

        return null;
    }

    /** Number of POST /generations requests received, replays included. */
    public function createCalls(): int
    {
        return $this->createCalls;
    }

    // ---------------------------------------------------------------------------------------
    // Payload builders
    // ---------------------------------------------------------------------------------------

    /**
     * GET /v1/pricing body.
     *
     * @param  array<string, string>  $rates
     * @return array<string, mixed>
     */
    public static function pricingJson(array $rates = self::RATES): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [...self::RATES, ...$rates],
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
     * A usage object with `total_tokens`, as in a generation's `usage`.
     *
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}
     */
    public static function usage(int $inputText, int $inputImage, int $output, int $reasoning): array
    {
        return [
            'input_text_tokens' => $inputText,
            'input_image_tokens' => $inputImage,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'total_tokens' => $inputText + $inputImage + $output + $reasoning,
        ];
    }

    /** @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int} */
    public static function workedExampleUsage(): array
    {
        return self::usage(...array_values(self::WORKED_EXAMPLE_USAGE));
    }

    /**
     * A generation object as returned by the API and carried in webhooks. The fields a status
     * implies (timestamps, usage, image URL, error) are filled consistently; `$overrides`
     * replace top-level keys. A failed generation takes its code from `error_code`
     * (default `generation_failed`).
     *
     * @param  'queued'|'processing'|'succeeded'|'failed'  $status
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generation(string $status = 'queued', array $overrides = []): array
    {
        $now = CarbonImmutable::now('UTC');
        $errorCode = $overrides['error_code'] ?? 'generation_failed';
        unset($overrides['error_code']);

        $generation = [
            'id' => 'gen_'.Str::lower(Str::random(24)),
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A cat sleeping on a keyboard',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::RATES,
            'error' => null,
            'created_at' => self::time($now->subSeconds(30)),
            'started_at' => null,
            'completed_at' => null,
            ...$overrides,
            'status' => $status,
        ];

        // Fill what the status implies, unless the caller set it explicitly.
        if (in_array($status, ['processing', 'succeeded', 'failed'], true) && ! array_key_exists('started_at', $overrides)) {
            $generation['started_at'] ??= self::time($now->subSeconds(20));
        }

        if (in_array($status, ['succeeded', 'failed'], true) && ! array_key_exists('completed_at', $overrides)) {
            $generation['completed_at'] ??= self::time($now);
        }

        if ($status === 'succeeded') {
            if (! array_key_exists('usage', $overrides)) {
                $generation['usage'] ??= self::workedExampleUsage();
            }
            if (! array_key_exists('image_url', $overrides)) {
                $generation['image_url'] ??= "https://cdn.pixelforge.test/images/{$generation['id']}.png?sig=test";
            }
            if (! array_key_exists('image_url_expires_at', $overrides) && $generation['completed_at'] !== null) {
                $generation['image_url_expires_at'] ??= self::time(CarbonImmutable::parse($generation['completed_at'])->addDay());
            }
            if (! array_key_exists('error', $overrides)) {
                $generation['error'] = null;
            }
        }

        if ($status === 'failed' && ! array_key_exists('error', $overrides)) {
            $generation['error'] ??= ['code' => $errorCode, 'message' => 'The generation failed.'];
        }

        if (in_array($status, ['queued', 'processing'], true)) {
            foreach (['usage', 'image_url', 'image_url_expires_at', 'error', 'completed_at'] as $key) {
                if (! array_key_exists($key, $overrides)) {
                    $generation[$key] = null;
                }
            }
        }

        return $generation;
    }

    /**
     * A generation object for one of our generations: same request fields, `metadata.local_id`
     * and, when known, its `pixelforge_id`.
     *
     * @param  'queued'|'processing'|'succeeded'|'failed'  $status
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generationFor(Generation $generation, string $status = 'queued', array $overrides = []): array
    {
        $attributes = array_filter([
            'id' => $generation->pixelforge_id,
            'prompt' => $generation->prompt,
            'size' => self::scalar($generation->size),
            'quality' => self::scalar($generation->quality),
            'reference_images' => $generation->reference_images ?? [],
            'metadata' => ['local_id' => (string) $generation->id],
        ], fn ($value) => $value !== null);

        return self::generation($status, [...$attributes, ...$overrides]);
    }

    /**
     * The error body every Pixelforge error uses.
     *
     * @return array{error: array<string, mixed>}
     */
    public static function errorBody(string $code, ?string $message = null): array
    {
        [, $type, $defaultMessage] = self::ERRORS[$code] ?? [500, 'api_error', 'Internal server error.'];

        $error = ['type' => $type, 'code' => $code, 'message' => $message ?? $defaultMessage];

        if ($code === 'validation_failed') {
            $error['param'] = 'prompt';
            $error['errors'] = ['prompt' => ['The prompt field is required.']];
        }

        return ['error' => $error];
    }

    /**
     * A signed webhook request exactly as Pixelforge sends it.
     *
     * Options: `event_id`, `attempt` (Pixelforge-Delivery-Attempt), `timestamp` (signature time,
     * default now), `secret` (default WEBHOOK_SECRET), `signature` (replace the whole
     * Pixelforge-Signature header), `created_at`.
     *
     * Post it with `$this->call('POST', '/webhooks/pixelforge', server: $webhook['server'], content: $webhook['body'])`.
     *
     * @param  string  $type  generation.processing|generation.succeeded|generation.failed|ping
     * @param  array<string, mixed>  $object  the generation object (data.object)
     * @param  array{event_id?: string, attempt?: int, timestamp?: int, secret?: string, signature?: string, created_at?: string}  $options
     * @return array{event: array<string, mixed>, body: string, headers: array<string, string>, server: array<string, string>}
     */
    public static function webhook(string $type, array $object = [], array $options = []): array
    {
        $eventId = $options['event_id'] ?? 'evt_'.Str::lower(Str::random(24));
        $timestamp = $options['timestamp'] ?? CarbonImmutable::now()->getTimestamp();

        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => $options['created_at'] ?? self::time(CarbonImmutable::now('UTC')),
            'data' => ['object' => $object],
        ];

        $body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Pixelforge-Webhooks/1.0',
            'Pixelforge-Event-Id' => $eventId,
            'Pixelforge-Event-Type' => $type,
            'Pixelforge-Delivery-Attempt' => (string) ($options['attempt'] ?? 1),
            'Pixelforge-Signature' => $options['signature']
                ?? WebhookSignature::sign($body, $options['secret'] ?? self::WEBHOOK_SECRET, $timestamp),
        ];

        return [
            'event' => $event,
            'body' => $body,
            'headers' => $headers,
            'server' => self::serverVariables($headers),
        ];
    }

    /**
     * The webhook for a generation object, with the event type its status implies.
     *
     * @param  array<string, mixed>  $generation
     * @param  array{event_id?: string, attempt?: int, timestamp?: int, secret?: string, signature?: string, created_at?: string}  $options
     * @return array{event: array<string, mixed>, body: string, headers: array<string, string>, server: array<string, string>}
     */
    public static function webhookFor(array $generation, array $options = []): array
    {
        return self::webhook('generation.'.$generation['status'], $generation, $options);
    }

    /**
     * Headers as `$_SERVER` variables, for `$this->call(..., server: ...)`.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function serverVariables(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    // ---------------------------------------------------------------------------------------
    // HTTP handling
    // ---------------------------------------------------------------------------------------

    /** @return PromiseInterface|\Closure|null null lets other fakes or preventStrayRequests() handle it */
    private function handle(Request $request): PromiseInterface|\Closure|null
    {
        $base = rtrim((string) config('pixelforge.base_url'), '/');

        if (! str_starts_with($request->url(), $base.'/')) {
            return null;
        }

        $path = '/'.ltrim((string) parse_url(substr($request->url(), strlen($base)), PHP_URL_PATH), '/');
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $method = strtoupper($request->method());

        if ($method === 'GET' && $path === '/pricing') {
            return Http::response($this->pricing, 200);
        }

        if (! $this->authorized($request)) {
            return $this->error('invalid_api_key');
        }

        return match (true) {
            $method === 'POST' && $path === '/generations' => $this->create($request),
            $method === 'GET' && preg_match('#^/generations/([^/]+)$#', $path, $match) === 1 => $this->get($match[1]),
            $method === 'GET' && $path === '/usage' => $this->usageResponse($query),
            default => $this->error('not_found', 'Unknown endpoint.'),
        };
    }

    private function authorized(Request $request): bool
    {
        $key = (string) config('pixelforge.api_key');

        return $key !== '' && $request->hasHeader('Authorization', 'Bearer '.$key);
    }

    private function create(Request $request): PromiseInterface|\Closure
    {
        $this->createCalls++;

        /** @var array<string, mixed> $params */
        $params = json_decode($request->body(), true) ?: [];
        $key = $request->header('Idempotency-Key')[0] ?? null;
        $hash = hash('sha256', json_encode(self::normalize($params), JSON_THROW_ON_ERROR));

        // Pixelforge replays an existing generation before it applies limits.
        if ($key !== null && isset($this->idempotencyKeys[$key])) {
            if ($this->idempotencyKeys[$key]['hash'] !== $hash) {
                return $this->error('idempotency_key_reused');
            }

            return $this->respond($this->generations[$this->idempotencyKeys[$key]['id']], 202, ['Idempotent-Replayed' => 'true']);
        }

        $unknown = array_diff(array_keys($params), self::CREATE_PARAMS);
        if ($unknown !== [] || ! is_string($params['prompt'] ?? null) || $params['prompt'] === '') {
            return $this->error('validation_failed');
        }

        foreach ((array) ($params['metadata'] ?? []) as $value) {
            if (! is_string($value)) {
                return $this->error('validation_failed');
            }
        }

        $outcome = array_shift($this->createOutcomes) ?? self::ACCEPTED;
        $code = is_array($outcome) ? $outcome['code'] : $outcome;
        $retryAfter = is_array($outcome) ? ($outcome['retry_after'] ?? null) : null;

        return match ($code) {
            self::ACCEPTED => $this->respond($this->accept($params, $key, $hash), 202),
            self::TIMEOUT_AFTER_CREATE => $this->timeoutAfter(fn () => $this->accept($params, $key, $hash)),
            self::TIMEOUT => Factory::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds'),
            self::SERVER_ERROR => $this->respond(self::errorBody('internal_error', 'Internal server error.'), 500),
            default => $this->error($code, retryAfter: $retryAfter),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function accept(array $params, ?string $key, string $hash): array
    {
        $generation = $this->store(self::generation('queued', [
            'prompt' => $params['prompt'],
            'size' => $params['size'] ?? '1024x1024',
            'quality' => $params['quality'] ?? 'medium',
            'reference_images' => $params['reference_images'] ?? [],
            'metadata' => $params['metadata'] ?? [],
            'rates' => $this->pricing['rates'],
            'created_at' => self::time(CarbonImmutable::now('UTC')),
        ]));

        if ($key !== null) {
            $this->idempotencyKeys[$key] = ['hash' => $hash, 'id' => $generation['id']];
        }

        return $generation;
    }

    private function timeoutAfter(\Closure $sideEffect): \Closure
    {
        $sideEffect();

        return Factory::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds');
    }

    private function get(string $id): PromiseInterface|\Closure
    {
        $outcome = array_shift($this->getOutcomes);

        if (is_array($outcome)) {
            return $this->respond($outcome, 200);
        }

        return match ($outcome) {
            null => isset($this->generations[$id])
                ? $this->respond($this->generations[$id], 200)
                : $this->error('not_found'),
            self::TIMEOUT => Factory::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds'),
            self::SERVER_ERROR => $this->respond(self::errorBody('internal_error', 'Internal server error.'), 500),
            default => $this->error($outcome),
        };
    }

    /** @param array<string, mixed> $query */
    private function usageResponse(array $query): PromiseInterface
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = isset($query['start_date']) ? CarbonImmutable::parse($query['start_date'], 'UTC') : $today->subDays(29);
        $end = isset($query['end_date']) ? CarbonImmutable::parse($query['end_date'], 'UTC') : $today;

        if ($start->diffInDays($end) + 1 > 92 || $end->lessThan($start)) {
            return $this->error('date_range_too_long');
        }

        $data = [];
        $tokenKeys = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];
        $total = ['generations' => 0, ...array_fill_keys($tokenKeys, 0)];
        $totalNanos = 0;

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $row = $this->usageDays[$day->toDateString()] ?? [];
            $entry = ['date' => $day->toDateString(), 'generations' => $row['generations'] ?? 0];
            foreach ($tokenKeys as $tokenKey) {
                $entry[$tokenKey] = $row[$tokenKey] ?? 0;
                $total[$tokenKey] += $entry[$tokenKey];
            }
            $entry['cost_usd'] = $row['cost_usd'] ?? '0.000000000';
            $total['generations'] += $entry['generations'];
            $totalNanos += self::nanos($entry['cost_usd']);
            $data[] = $entry;
        }

        return $this->respond([
            'object' => 'usage',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'data' => $data,
            'total' => [...$total, 'cost_usd' => sprintf('%d.%09d', intdiv($totalNanos, 1_000_000_000), $totalNanos % 1_000_000_000)],
        ], 200);
    }

    private function error(string $code, ?string $message = null, ?int $retryAfter = null): PromiseInterface
    {
        $status = self::ERRORS[$code][0] ?? 500;
        $headers = [];

        $retryAfter ??= self::RETRY_AFTER[$code] ?? null;
        if ($code === 'daily_limit_exceeded') {
            $retryAfter ??= (int) CarbonImmutable::now('UTC')->diffInSeconds(CarbonImmutable::now('UTC')->addDay()->startOfDay());
        }
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return $this->respond(self::errorBody($code, $message), $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function respond(array $body, int $status, array $headers = []): PromiseInterface
    {
        return Http::response($body, $status, [
            'Content-Type' => 'application/json',
            'X-RateLimit-Limit' => '60',
            'X-RateLimit-Remaining' => (string) $this->rateLimitRemaining,
            ...$headers,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function normalize(array $params): array
    {
        $params += ['size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], 'metadata' => []];
        $metadata = (array) $params['metadata'];
        ksort($metadata);
        $params['metadata'] = $metadata;
        ksort($params);

        return $params;
    }

    /** "0.373875000" → 373875000, digits only (the fake never uses floats either). */
    private static function nanos(string $usd): int
    {
        [$whole, $fraction] = array_pad(explode('.', $usd, 2), 2, '');

        return (int) $whole * 1_000_000_000 + (int) str_pad(substr($fraction, 0, 9), 9, '0');
    }

    private static function time(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
