<?php

use App\Billing\Ledger;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Tests\Support\FakePixelforge;

/**
 * POST /generations/estimate (contracts/http.md): the live estimate the form shows.
 * All amounts are user prices as {display, exact} strings; the breakdown is provider cost.
 */
beforeEach(function () {
    config(['pixelcat.markup_percent' => 25]);
    $this->pixelforge = FakePixelforge::fake();
    $this->user = User::factory()->create();
    app(Ledger::class)->grant($this->user, 2_000_000_000, 'Welcome credit');

    $this->estimate = fn (array $input = []) => $this->actingAs($this->user)->postJson('/generations/estimate', [
        'prompt' => str_repeat('x', 40),
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        ...$input,
    ]);
});

it('returns the estimate range, breakdown and balance', function () {
    // 40 characters → 10 text tokens (8…12); medium square: 1,100 output, 200…2,000 reasoning.
    $response = ($this->estimate)();

    $response->assertOk()->assertExactJson([
        'min' => ['display' => '$0.0582', 'exact' => '0.058175000'],
        'likely' => ['display' => '$0.0723', 'exact' => '0.072250000'],
        'max' => ['display' => '$0.0863', 'exact' => '0.086325000'],
        'breakdown' => [
            ['type' => 'input_text_tokens', 'tokens' => '8–12', 'cost' => '$0.0000–$0.0001'],
            ['type' => 'input_image_tokens', 'tokens' => '0', 'cost' => '$0.0000'],
            ['type' => 'output_tokens', 'tokens' => '1,100', 'cost' => '$0.0440'],
            ['type' => 'reasoning_tokens', 'tokens' => '200–2,000', 'cost' => '$0.0025–$0.0250'],
        ],
        'pricing_version' => app(PixelforgeClient::class)->pricing()->version(),
        'rates_fetched_at' => $response->json('rates_fetched_at'),
        'affordable' => true,
        'balance' => ['display' => '$2.0000', 'exact' => '2.000000000'],
    ]);

    expect($response->json('rates_fetched_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('prices the worked example', function () {
    $response = ($this->estimate)([
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ]);

    // The docs' maximum usage (115 text tokens, 8,000 reasoning) is the estimate's maximum.
    $response->assertOk()
        ->assertJsonPath('max.exact', '0.467343750')
        ->assertJsonPath('breakdown.1', ['type' => 'input_image_tokens', 'tokens' => '1,530', 'cost' => '$0.0153']);
});

it('accepts an empty prompt while the user is typing', function () {
    ($this->estimate)(['prompt' => ''])->assertOk()->assertJsonPath('breakdown.0.tokens', '0–2');
});

it('counts multi-byte prompts in characters', function () {
    // 13 characters (4 text tokens), although they take 26 bytes in UTF-8.
    ($this->estimate)(['prompt' => 'Котик спить 😺'])->assertOk()->assertJsonPath('breakdown.0.tokens', '3–5');
});

it('says when the balance does not cover the maximum', function () {
    $poor = User::factory()->create();
    app(Ledger::class)->grant($poor, 86_325_000 - 1, 'Welcome credit');

    $this->actingAs($poor)->postJson('/generations/estimate', [
        'prompt' => str_repeat('x', 40), 'size' => '1024x1024', 'quality' => 'medium',
    ])->assertOk()
        ->assertJsonPath('affordable', false)
        ->assertJsonPath('balance.exact', '0.086324999');
});

it('is affordable when the balance equals the maximum exactly', function () {
    $exact = User::factory()->create();
    app(Ledger::class)->grant($exact, 86_325_000, 'Welcome credit');

    $this->actingAs($exact)->postJson('/generations/estimate', [
        'prompt' => str_repeat('x', 40), 'size' => '1024x1024', 'quality' => 'medium',
    ])->assertOk()->assertJsonPath('affordable', true);
});

it('rejects invalid input with 422', function (array $input, string $field) {
    ($this->estimate)($input)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'prompt too long' => [['prompt' => str_repeat('x', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'five references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'ftp url' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);

it('requires a signed-in user', function () {
    $this->postJson('/generations/estimate', ['prompt' => 'x'])->assertUnauthorized();
});
