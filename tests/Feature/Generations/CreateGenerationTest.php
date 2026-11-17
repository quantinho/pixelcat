<?php

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\DispatchWaiting;
use App\Jobs\ExpireWaitingGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakePixelforge;

/**
 * POST /generations and GET /generate (contracts/http.md, pages.md; FR-002, FR-004, FR-005,
 * FR-007, FR-015, FR-017). Submitting creates a `waiting` generation and its hold in one
 * transaction and returns at once; queued jobs do the rest.
 */
const CREATE_MAX = 86_325_000; // estimate maximum for a 40-character medium square prompt

beforeEach(function () {
    config(['pixelcat.markup_percent' => 25, 'pixelcat.daily_cap_per_user' => 50, 'pixelcat.max_wait_minutes' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    Queue::fake();
    $this->withoutVite(); // the Create page is built by the frontend slice

    $this->pixelforge = FakePixelforge::fake();
    $this->ledger = app(Ledger::class);
    $this->user = User::factory()->create();
    $this->ledger->grant($this->user, 2_000_000_000, 'Welcome credit');
    $this->version = app(PixelforgeClient::class)->pricing()->version();

    $this->input = fn (array $overrides = []) => [
        'id' => (string) Str::uuid(),
        'prompt' => str_repeat('x', 40),
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'pricing_version' => $this->version,
        ...$overrides,
    ];

    $this->submit = fn (array $input, ?User $as = null) => $this->actingAs($as ?? $this->user)->post('/generations', $input);
    $this->submitJson = fn (array $input, ?User $as = null) => $this->actingAs($as ?? $this->user)->postJson('/generations', $input);
});

it('creates a waiting generation with a hold of the estimate maximum', function () {
    $input = ($this->input)(['reference_images' => ['https://example.com/cat.png']]);

    ($this->submit)($input)
        ->assertStatus(303)
        ->assertRedirect('/generations')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Generating…']);

    $generation = Generation::query()->findOrFail($input['id']);

    expect($generation->user_id)->toBe($this->user->id)
        ->and($generation->status)->toBe(GenerationStatus::Waiting)
        ->and($generation->prompt)->toBe($input['prompt'])
        ->and($generation->size->value)->toBe('1024x1024')
        ->and($generation->quality->value)->toBe('medium')
        ->and($generation->reference_images)->toBe(['https://example.com/cat.png'])
        ->and($generation->estimate_min)->toBe(58_175_000 + 9_562_500)
        ->and($generation->estimate_likely)->toBe(72_250_000 + 9_562_500)
        ->and($generation->estimate_max)->toBe(CREATE_MAX + 9_562_500)
        ->and($generation->estimate_rates)->toEqual(FakePixelforge::RATES)
        ->and($generation->request_hash)->toHaveLength(64)
        ->and($generation->available_at->equalTo(now()))->toBeTrue()
        ->and($generation->pixelforge_id)->toBeNull();

    expect(LedgerEntry::query()->where('generation_id', $generation->id)->sole())
        ->type->toBe(LedgerEntryType::Hold)
        ->amount->toBe(-(CREATE_MAX + 9_562_500));
    expect($this->ledger->balance($this->user))->toBe(2_000_000_000 - CREATE_MAX - 9_562_500);
    expect($generation->events()->pluck('type')->all())->toBe(['submitted']);

    // Nothing is sent to Pixelforge from the request itself.
    expect($this->pixelforge->createCalls())->toBe(0);
});

it('queues the slot dispatcher and an expiry check at the waiting limit', function () {
    $input = ($this->input)();

    ($this->submit)($input)->assertStatus(303);

    Queue::assertPushed(DispatchWaiting::class, 1);
    Queue::assertPushed(ExpireWaitingGeneration::class, function (ExpireWaitingGeneration $job) use ($input) {
        return $job->generation->id === $input['id'] && $job->delay === 15 * 60;
    });
});

it('caps the expiry delay at 15 minutes when the waiting limit is longer', function () {
    config(['pixelcat.max_wait_minutes' => 40]);

    ($this->submit)(($this->input)())->assertStatus(303);

    Queue::assertPushed(ExpireWaitingGeneration::class, fn (ExpireWaitingGeneration $job) => $job->delay === 15 * 60);
});

it('treats the same id with the same parameters as a resubmit', function () {
    $input = ($this->input)();

    ($this->submit)($input)->assertStatus(303);
    ($this->submit)($input)->assertStatus(303)->assertRedirect('/generations');

    expect(Generation::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Hold)->count())->toBe(1);
    Queue::assertPushed(DispatchWaiting::class, 1);
    Queue::assertPushed(ExpireWaitingGeneration::class, 1);
});

it('accepts a resubmit even after prices changed or the daily cap was reached', function () {
    $input = ($this->input)();
    ($this->submit)($input)->assertStatus(303);

    config(['pixelcat.daily_cap_per_user' => 1]);
    $this->pixelforge->withRates(['output_tokens' => '50.00']);
    cache()->flush();

    ($this->submit)($input)->assertStatus(303)->assertRedirect('/generations');
    expect(Generation::query()->count())->toBe(1);
});

it('refuses the same id with other parameters', function () {
    $input = ($this->input)();
    ($this->submit)($input)->assertStatus(303);

    ($this->submitJson)([...$input, 'prompt' => 'Something else entirely'])
        ->assertStatus(409)
        ->assertJsonValidationErrors('id_reused');

    expect(Generation::query()->count())->toBe(1)
        ->and(Generation::query()->sole()->prompt)->toBe($input['prompt']);
});

it('refuses an id that belongs to another user', function () {
    $other = Generation::factory()->create();

    ($this->submitJson)(($this->input)(['id' => $other->id]))
        ->assertStatus(409)
        ->assertJsonValidationErrors('id_reused');

    expect(Generation::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('sends Inertia form errors back to the form', function () {
    $input = ($this->input)();
    ($this->submit)($input)->assertStatus(303);

    $this->from('/generate');
    ($this->submit)([...$input, 'quality' => 'high'])
        ->assertRedirect('/generate')
        ->assertSessionHasErrors('id_reused');
});

it('refuses a submission priced with old rates', function () {
    ($this->submitJson)(($this->input)(['pricing_version' => str_repeat('0', 64)]))
        ->assertStatus(409)
        ->assertJsonValidationErrors('prices_changed');

    expect(Generation::query()->count())->toBe(0)
        ->and($this->ledger->balance($this->user))->toBe(2_000_000_000);
});

it('refuses a submission when the balance is below the estimate maximum', function () {
    $poor = User::factory()->create();
    $this->ledger->grant($poor, CREATE_MAX - 10_000_000, 'Welcome credit');

    $response = ($this->submitJson)(($this->input)(), $poor)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('insufficient_balance');

    expect($response->json('errors.insufficient_balance.0'))->toContain('$0.0100');
    expect(Generation::query()->count())->toBe(0)
        ->and($this->ledger->balance($poor))->toBe(CREATE_MAX - 10_000_000);
});

it('accepts a submission when the balance equals the estimate maximum', function () {
    $exact = User::factory()->create();
    $this->ledger->grant($exact, CREATE_MAX, 'Welcome credit');

    ($this->submit)(($this->input)(), $exact)->assertStatus(303);

    expect($this->ledger->balance($exact))->toBe(0);
});

it('refuses the 51st generation of the UTC day, failed ones included', function () {
    Generation::factory()->for($this->user)->count(30)->succeeded()->create();
    Generation::factory()->for($this->user)->count(20)->failed()->create();
    Generation::factory()->for($this->user)->count(5)->succeeded()->create(['created_at' => now()->subDay()]);

    $response = ($this->submitJson)(($this->input)())
        ->assertStatus(429)
        ->assertJsonValidationErrors('daily_cap');

    expect($response->json('errors.daily_cap.0'))->toContain('50')->toContain('midnight UTC');
    expect(Generation::query()->count())->toBe(55)
        ->and($this->ledger->balance($this->user))->toBe(2_000_000_000);
});

it('allows the 50th generation of the UTC day', function () {
    Generation::factory()->for($this->user)->count(49)->succeeded()->create();

    ($this->submit)(($this->input)())->assertStatus(303);

    expect(Generation::query()->count())->toBe(50);
});

it('refuses with a clear message when Pixelforge pricing is unreachable', function (Closure $response) {
    Cache::flush();
    Http::swap(new Factory); // drop the fake Pixelforge
    Http::preventStrayRequests();
    Http::fake(fn () => $response());

    ($this->submitJson)(($this->input)())
        ->assertStatus(503)
        ->assertJsonPath('errors.pixelforge_unavailable.0', 'Pixelforge is unreachable right now. Try again in a moment.');

    expect(Generation::query()->count())->toBe(0)
        ->and($this->ledger->balance($this->user))->toBe(2_000_000_000);
    Queue::assertNothingPushed();
})->with([
    'timeout' => fn () => fn () => Factory::failedConnection('cURL error 28: Operation timed out'),
    'overloaded' => fn () => fn () => Http::response(FakePixelforge::errorBody('overloaded'), 503, ['Retry-After' => '2']),
]);

it('validates the request like Pixelforge does', function (array $overrides, string $field) {
    ($this->submitJson)(($this->input)($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Generation::query()->count())->toBe(0);
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'prompt over 4,000 characters' => [['prompt' => str_repeat('я', 4001)], 'prompt'],
    'unknown size' => [['size' => '2048x2048'], 'size'],
    'missing size' => [['size' => null], 'size'],
    'unknown quality' => [['quality' => 'best'], 'quality'],
    'five references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'reference not a url' => [['reference_images' => ['cat.png']], 'reference_images.0'],
    'reference not http' => [['reference_images' => ['javascript://example.com/a']], 'reference_images.0'],
    'references not a list' => [['reference_images' => 'https://example.com/a.png'], 'reference_images'],
    'id not a uuid' => [['id' => 'abc'], 'id'],
    'missing pricing version' => [['pricing_version' => null], 'pricing_version'],
]);

it('accepts a 4,000-character multi-byte prompt and four references', function () {
    $input = ($this->input)([
        'prompt' => str_repeat('я', 4000),
        'reference_images' => array_fill(0, 4, 'http://example.com/a.png'),
    ]);

    ($this->submit)($input)->assertStatus(303);

    expect(Generation::query()->findOrFail($input['id'])->prompt)->toBe(str_repeat('я', 4000));
});

it('requires a signed-in user', function () {
    $this->post('/generations', ($this->input)())->assertRedirect('/login');
    $this->get('/generate')->assertRedirect('/login');
});

// GET /generate ------------------------------------------------------------------------------

it('renders the generate page with defaults, keywords and the daily count', function () {
    Generation::factory()->for($this->user)->count(3)->succeeded()->create();
    Generation::factory()->for($this->user)->succeeded()->create(['created_at' => now()->subDay()]);

    $this->actingAs($this->user)->get('/generate')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generations/Create', false)
            ->where('defaults', ['size' => '1024x1024', 'quality' => 'medium'])
            ->where('prefill', null)
            ->where('keywords', ['[succeed]', '[fail]', '[slow]', '[duplicate]', '[overloaded]', '[blocked]'])
            ->where('cat_prompts', fn ($prompts) => count($prompts) >= 5 && collect($prompts)->every(fn ($prompt) => is_string($prompt) && $prompt !== ''))
            ->where('daily', ['used' => 3, 'cap' => 50, 'resets_at' => '2026-11-18T00:00:00+00:00']));
});

it('pre-fills the form from the user\'s own failed generation and submits nothing', function () {
    $failed = Generation::factory()->for($this->user)->failed('internal_error')->create([
        'prompt' => 'A cat astronaut',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/cat.png'],
    ]);
    $countBefore = Generation::query()->count();

    $this->actingAs($this->user)->get("/generate?retry={$failed->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generations/Create', false)
            ->where('prefill', [
                'prompt' => 'A cat astronaut',
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => ['https://example.com/cat.png'],
            ]));

    expect(Generation::query()->count())->toBe($countBefore)
        ->and($this->pixelforge->createCalls())->toBe(0);
    Queue::assertNothingPushed();
});

it('ignores retry ids it must not use', function (Closure $retry) {
    $this->actingAs($this->user)->get('/generate?retry='.$retry($this->user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generations/Create', false)
            ->where('prefill', null));
})->with([
    // Closures typed as Closure reach the test unresolved; it calls them with its user.
    'another user\'s failed generation' => fn () => Generation::factory()->failed()->create()->id,
    'an unfinished generation' => fn (User $user) => Generation::factory()->for($user)->queued()->create()->id,
    'an unknown id' => fn () => (string) Str::uuid(),
    'not a uuid' => fn () => 'nope',
]);
