<?php

use App\Actions\Pricing\CalculateCost;
use App\Billing\Ledger;
use App\Billing\Money;
use App\Dev\FakePixelforge\AdvanceFakeGeneration;
use App\Dev\FakePixelforge\FakeGenerations;
use App\Dev\FakePixelforge\SendFakeWebhook;
use App\Enums\GenerationStatus;
use App\Events\GenerationFinished;
use App\Events\GenerationSucceeded;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePixelforge;

/**
 * The local fake Pixelforge (T060) answers like the documented API and drives this app
 * through signed webhooks.
 */
beforeEach(function () {
    // Registered at boot only when PIXELFORGE_FAKE=true; tests run with it off.
    Route::prefix('_fake/pixelforge/v1')->group(base_path('routes/fake-pixelforge.php'));
    Queue::fake([AdvanceFakeGeneration::class, SendFakeWebhook::class]);
    Event::fake([GenerationSucceeded::class, GenerationFinished::class]);
});

/** @param array<string, mixed> $params */
function fakeCreate(array $params, ?string $key = null, string $token = 'test-key'): TestResponse
{
    return test()->postJson('/_fake/pixelforge/v1/generations', $params, array_filter([
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => $key,
    ]));
}

/** Run the queued fake jobs as the worker would, delivering webhooks to this app. */
function runFakeJobs(string $class): void
{
    Http::fake([url('/webhooks/pixelforge') => function (ClientRequest $request) {
        $headers = array_map(fn (array $values) => $values[0], $request->headers());
        $response = test()->call('POST', '/webhooks/pixelforge', server: FakePixelforge::serverVariables($headers), content: $request->body());

        return Http::response(null, $response->status());
    }]);

    foreach (Queue::pushed($class) as $job) {
        app()->call([$job, 'handle']);
    }
}

it('publishes the documented pricing', function () {
    $this->getJson('/_fake/pixelforge/v1/pricing')->assertOk()->assertExactJson(FakePixelforge::pricingJson());
});

it('accepts a generation and schedules it to start', function () {
    $response = fakeCreate(['prompt' => 'A cat', 'quality' => 'low', 'metadata' => ['local_id' => 'abc']], 'key-1');

    $response->assertStatus(202)
        ->assertHeader('X-RateLimit-Remaining', '59')
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('quality', 'low')
        ->assertJsonPath('size', '1024x1024')
        ->assertJsonPath('metadata.local_id', 'abc')
        ->assertJsonPath('rates', FakePixelforge::RATES);
    Queue::assertPushed(AdvanceFakeGeneration::class, fn ($job) => $job->status === 'processing' && $job->delay === 3);

    $this->getJson('/_fake/pixelforge/v1/generations/'.$response->json('id'), ['Authorization' => 'Bearer test-key'])
        ->assertOk()
        ->assertJsonPath('id', $response->json('id'));
});

it('replays an Idempotency-Key and refuses it with other parameters', function () {
    $first = fakeCreate(['prompt' => 'A cat'], 'key-1');

    fakeCreate(['prompt' => 'A cat'], 'key-1')
        ->assertStatus(202)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('id', $first->json('id'));
    fakeCreate(['prompt' => 'A dog'], 'key-1')->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('answers errors in the documented shape', function (array $params, string $token, int $status, string $code) {
    fakeCreate($params, token: $token)->assertStatus($status)->assertJsonPath('error.code', $code);
})->with([
    'wrong key' => [['prompt' => 'A cat'], 'nope', 401, 'invalid_api_key'],
    'unknown parameter' => [['prompt' => 'A cat', 'style' => 'noir'], 'test-key', 422, 'validation_failed'],
    'empty prompt' => [['prompt' => ''], 'test-key', 422, 'validation_failed'],
    'five references' => [['prompt' => 'A cat', 'reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'test-key', 422, 'validation_failed'],
    'metadata not a string' => [['prompt' => 'A cat', 'metadata' => ['n' => 1]], 'test-key', 422, 'validation_failed'],
    '[blocked]' => [['prompt' => 'A cat [blocked]'], 'test-key', 400, 'content_policy_violation'],
    '[overloaded]' => [['prompt' => 'A cat [overloaded]'], 'test-key', 503, 'overloaded'],
]);

it('limits the account to 3 generations in progress', function () {
    foreach (range(1, 3) as $i) {
        fakeCreate(['prompt' => "Cat {$i}"])->assertStatus(202);
    }

    fakeCreate(['prompt' => 'Cat 4'])
        ->assertStatus(429)
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('error.code', 'concurrency_limit_exceeded');
});

it('drives a generation of this app to success through signed webhooks', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->submitting()->create(['pixelforge_id' => null, 'estimate_max' => 900_000_000]);
    app(Ledger::class)->hold($generation, 900_000_000);
    $id = fakeCreate(['prompt' => $generation->prompt, 'metadata' => ['local_id' => $generation->id]], $generation->id)->json('id');

    runFakeJobs(AdvanceFakeGeneration::class);
    runFakeJobs(SendFakeWebhook::class);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->fresh()->pixelforge_id)->toBe($id);

    Queue::fake([AdvanceFakeGeneration::class, SendFakeWebhook::class]);
    (new AdvanceFakeGeneration($id, 'succeeded'))->handle(app(FakeGenerations::class));
    runFakeJobs(SendFakeWebhook::class);

    $generation->refresh();
    $remote = $this->getJson("/_fake/pixelforge/v1/generations/{$id}", ['Authorization' => 'Bearer test-key'])->json();
    $cost = app(CalculateCost::class)->providerCost($remote['usage'], $remote['rates']);
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost)->toBe($cost)
        ->and($generation->charged)->toBe(app(CalculateCost::class)->userPrice($cost))
        ->and($generation->charged)->toBeLessThanOrEqual($generation->estimate_max)
        ->and($generation->charged)->toBeGreaterThanOrEqual($generation->estimate_min);

    $this->getJson('/_fake/pixelforge/v1/usage', ['Authorization' => 'Bearer test-key'])
        ->assertOk()
        ->assertJsonPath('total.generations', 1)
        ->assertJsonPath('total.cost_usd', Money::toDecimal($cost));

    $image = $this->get($remote['image_url']);
    $image->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(substr((string) $image->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('ends a [fail] generation as failed', function () {
    $id = fakeCreate(['prompt' => 'A cat [fail]'])->json('id');

    runFakeJobs(AdvanceFakeGeneration::class);

    Queue::assertPushed(AdvanceFakeGeneration::class, fn ($job) => $job->status === 'failed');
});

it('delivers [duplicate] events twice and a late processing event', function () {
    $id = fakeCreate(['prompt' => 'A cat [duplicate]'])->json('id');
    $fake = app(FakeGenerations::class);
    (new AdvanceFakeGeneration($id, 'processing'))->handle($fake);
    Queue::fake([AdvanceFakeGeneration::class, SendFakeWebhook::class]);

    (new AdvanceFakeGeneration($id, 'succeeded'))->handle($fake);

    $sent = collect(Queue::pushed(SendFakeWebhook::class));
    expect($sent->map(fn (SendFakeWebhook $job) => $job->type)->all())
        ->toBe(['generation.succeeded', 'generation.succeeded', 'generation.processing'])
        ->and($sent[0]->eventId)->toBe($sent[1]->eventId)
        ->and($sent[2]->eventId)->not->toBe($sent[0]->eventId);
});

it('signs webhooks with the configured secret', function () {
    Http::fake();
    $object = FakePixelforge::generation('processing');

    (new SendFakeWebhook('evt_1', 'generation.processing', $object))->handle();

    Http::assertSent(function (ClientRequest $request) {
        return $request->url() === url('/webhooks/pixelforge')
            && $request->header('Pixelforge-Event-Id')[0] === 'evt_1'
            && app(WebhookSignature::class)->verify($request->header('Pixelforge-Signature')[0], $request->body());
    });
});
