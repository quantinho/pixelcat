<?php

use App\Actions\Pricing\EstimatePrice;
use App\Billing\Ledger;
use App\Billing\Money;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeUnavailable;
use App\Pixelforge\Pricing;
use Database\Factories\GenerationFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('treats a successful create without a readable body as an unknown outcome', function (string $body) {
    Http::fake(['pixelforge.test/*' => Http::response($body, 202)]);
    $generation = Generation::factory()->submitting()->create();

    app(PixelforgeClient::class)->createGeneration($generation);
})->with([
    'empty body' => [''],
    'html page' => ['<html>Gateway</html>'],
    'json without a generation' => ['{"ok":true}'],
])->throws(PixelforgeUnavailable::class);

it('keeps a non-whole-percent token variance exact and rounds the text range outwards', function () {
    $json = GenerationFactory::DOCUMENTED_PRICING;
    $json['tokens']['text_token_variance'] = 0.125;
    $pricing = Pricing::fromArray($json);

    // 40 characters → 10 estimated text tokens; ±12.5% → 8.75 … 11.25 → 8 … 12.
    $estimate = app(EstimatePrice::class)(str_repeat('a', 40), Size::Square, Quality::Low, 0, $pricing);
    $text = collect($estimate->breakdown)->firstWhere('type', 'input_text_tokens');

    expect($pricing->textTokenVariancePercent)->toBe(13)
        ->and($text['min_tokens'])->toBe(8)
        ->and($text['max_tokens'])->toBe(12);
});

it('refuses ledger updates in the database, not only in the model', function () {
    $user = User::factory()->create();
    app(Ledger::class)->grant($user, 2_000_000_000, 'Welcome credit');

    DB::table('ledger_entries')->where('user_id', $user->id)->update(['amount' => 0]);
})->throws(QueryException::class, 'append-only');

it('still deletes a user together with their ledger entries', function () {
    $user = User::factory()->create();
    app(Ledger::class)->grant($user, 2_000_000_000, 'Welcome credit');

    $user->delete();

    expect(DB::table('ledger_entries')->where('user_id', $user->id)->count())->toBe(0);
});

it('reports an out-of-range USD amount as invalid input', function () {
    Money::fromDecimal('99999999999.000000000');
})->throws(InvalidArgumentException::class);
