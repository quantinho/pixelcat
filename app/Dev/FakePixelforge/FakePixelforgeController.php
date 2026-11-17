<?php

namespace App\Dev\FakePixelforge;

use App\Actions\Pricing\CalculateCost;
use App\Billing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * DEV ONLY. A local stand-in for the Pixelforge API under /_fake/pixelforge/v1, registered only
 * when PIXELFORGE_FAKE=true outside production (bootstrap/app.php). Same shapes and errors as
 * the documented API, including the test keywords in prompts:
 *
 *   [blocked] 400 content_policy_violation    [overloaded] 503 overloaded (Retry-After: 2)
 *   [fail]    ends generation_failed          [slow]       takes about a minute
 *   [duplicate] webhooks arrive twice, plus a late processing event
 *   [succeed] succeeds (the default)
 *
 * Accepted generations are advanced by queued jobs that post signed webhooks to this app,
 * so run a queue worker (`composer run dev` does).
 */
class FakePixelforgeController
{
    private const CREATE_PARAMS = ['prompt', 'size', 'quality', 'reference_images', 'metadata'];

    /** @var array<string, array{0: int, 1: string, 2: string}> code => [status, type, message] */
    private const ERRORS = [
        'content_policy_violation' => [400, 'invalid_request_error', 'The prompt was rejected by the content policy.'],
        'invalid_api_key' => [401, 'authentication_error', 'The API key is missing, malformed or revoked.'],
        'not_found' => [404, 'invalid_request_error', 'No such generation.'],
        'idempotency_key_reused' => [409, 'idempotency_error', 'This Idempotency-Key was used with different parameters.'],
        'validation_failed' => [422, 'invalid_request_error', 'The request is invalid.'],
        'date_range_too_long' => [422, 'invalid_request_error', 'The date range may not exceed 92 days.'],
        'concurrency_limit_exceeded' => [429, 'rate_limit_error', 'Too many generations in progress.'],
        'overloaded' => [503, 'api_error', 'Pixelforge is overloaded. Try again shortly.'],
    ];

    public function __construct(private FakeGenerations $generations) {}

    public function pricing(): JsonResponse
    {
        return response()->json(FakeGenerations::pricing());
    }

    public function create(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->error('invalid_api_key');
        }

        /** @var array<string, mixed> $params */
        $params = $request->json()->all();
        $key = $request->header('Idempotency-Key');
        $hash = hash('sha256', (string) json_encode($this->normalize($params)));

        // Like Pixelforge: an existing generation is replayed before limits apply.
        if (is_string($key) && ($known = $this->generations->idempotencyKey($key)) !== null) {
            if ($known['hash'] !== $hash) {
                return $this->error('idempotency_key_reused');
            }

            return $this->respond($this->generations->find($known['id']) ?? [], 202, ['Idempotent-Replayed' => 'true']);
        }

        $validation = $this->validateCreate($params);
        if ($validation !== null) {
            return $validation;
        }

        $prompt = mb_strtolower((string) $params['prompt']);

        if (str_contains($prompt, '[blocked]')) {
            return $this->error('content_policy_violation');
        }

        if (str_contains($prompt, '[overloaded]')) {
            return $this->error('overloaded', ['Retry-After' => '2']);
        }

        if ($this->generations->activeCount() >= FakeGenerations::MAX_ACTIVE) {
            return $this->error('concurrency_limit_exceeded', ['Retry-After' => '5']);
        }

        $generation = $this->generations->create($params);

        if (is_string($key)) {
            $this->generations->rememberIdempotencyKey($key, $hash, $generation['id']);
        }

        $slow = str_contains($prompt, '[slow]');
        AdvanceFakeGeneration::dispatch($generation['id'], 'processing')->delay($slow ? 15 : 3);

        return $this->respond($generation, 202);
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->error('invalid_api_key');
        }

        return $this->respond(['object' => 'list', 'data' => array_slice($this->generations->all(), 0, 20)], 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->error('invalid_api_key');
        }

        $generation = $this->generations->find($id);

        return $generation === null ? $this->error('not_found') : $this->respond($generation, 200);
    }

    public function usage(Request $request, CalculateCost $calculateCost): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->error('invalid_api_key');
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = CarbonImmutable::parse($request->query('start_date', $today->subDays(29)->toDateString()), 'UTC');
        $end = CarbonImmutable::parse($request->query('end_date', $today->toDateString()), 'UTC');

        if ($end->lessThan($start) || $start->diffInDays($end) + 1 > 92) {
            return $this->error('date_range_too_long');
        }

        $tokenTypes = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];
        $days = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['generations' => 0, 'nanos' => 0, ...array_fill_keys($tokenTypes, 0)];
        }

        // Pixelforge bills a generation on its UTC completion day.
        foreach ($this->generations->all() as $generation) {
            $date = $generation['status'] === 'succeeded' ? substr((string) $generation['completed_at'], 0, 10) : null;
            if ($date === null || ! isset($days[$date])) {
                continue;
            }

            $usage = FakeGenerations::usageOf($generation);
            $days[$date]['generations']++;
            // The fake's rates never change, so every generation was created at these.
            $days[$date]['nanos'] += $calculateCost->providerCost($usage, FakeGenerations::RATES);
            foreach ($tokenTypes as $type) {
                $days[$date][$type] += $usage[$type];
            }
        }

        $data = [];
        $total = ['generations' => 0, 'nanos' => 0, ...array_fill_keys($tokenTypes, 0)];
        foreach ($days as $date => $row) {
            foreach ($row as $field => $value) {
                $total[$field] += $value;
            }
            $data[] = ['date' => $date, ...$this->usageRow($row)];
        }

        return $this->respond([
            'object' => 'usage',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'data' => $data,
            'total' => $this->usageRow($total),
        ], 200);
    }

    /**
     * A small PNG, so image copies work locally. Not authenticated, like a signed CDN URL.
     */
    public function image(string $id): Response
    {
        abort_if($this->generations->find($id) === null, 404);

        return response(FakeImage::png($id), 200, ['Content-Type' => 'image/png']);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function validateCreate(array $params): ?JsonResponse
    {
        $unknown = array_diff(array_keys($params), self::CREATE_PARAMS);

        $validator = Validator::make($params, [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['sometimes', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['sometimes', 'array', 'max:4'],
            'reference_images.*' => ['url:http,https'],
            'metadata' => ['sometimes', 'array'],
            'metadata.*' => ['string'],
        ]);

        if ($unknown === [] && $validator->passes()) {
            return null;
        }

        $errors = $validator->errors()->toArray();
        foreach ($unknown as $param) {
            $errors[$param] = ["The {$param} parameter is not supported."];
        }

        $body = $this->errorBody('validation_failed');
        $body['error']['param'] = array_key_first($errors);
        $body['error']['errors'] = $errors;

        return $this->respond($body, 422);
    }

    private function authorized(Request $request): bool
    {
        $key = (string) config('pixelforge.api_key');

        return $key !== '' && $request->bearerToken() === $key;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(string $code, array $headers = []): JsonResponse
    {
        return $this->respond($this->errorBody($code), self::ERRORS[$code][0], $headers);
    }

    /** @return array{error: array<string, mixed>} */
    private function errorBody(string $code): array
    {
        [, $type, $message] = self::ERRORS[$code];

        return ['error' => ['type' => $type, 'code' => $code, 'message' => $message]];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function respond(array $body, int $status, array $headers = []): JsonResponse
    {
        return response()->json($body, $status, [
            'X-RateLimit-Limit' => '60',
            'X-RateLimit-Remaining' => '59',
            ...$headers,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, int>  $row
     * @return array<string, int|string>
     */
    private function usageRow(array $row): array
    {
        $nanos = $row['nanos'];
        unset($row['nanos']);

        return [...$row, 'cost_usd' => Money::toDecimal($nanos)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function normalize(array $params): array
    {
        $params += ['size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], 'metadata' => []];
        $metadata = (array) $params['metadata'];
        ksort($metadata);
        $params['metadata'] = $metadata;
        ksort($params);

        return $params;
    }
}
