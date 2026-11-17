<?php

use App\Billing\Money;
use App\Models\Generation;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakePixelforge;

beforeEach(function () {
    // "Today" is 2026-11-17 UTC, so the last completed day is 2026-11-16.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'));
    $this->pixelforge = FakePixelforge::fake();
});

/** A succeeded generation with a known provider cost, completed at the given UTC time. */
function reconcileCompletedGeneration(string $completedAt, int $providerCost, array $overrides = []): Generation
{
    return Generation::factory()->succeeded()->create([
        'provider_cost' => $providerCost,
        'charged' => Money::applyMarkup($providerCost, 25),
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
        ...$overrides,
    ]);
}

function storedReconciliation(string $date): ?Reconciliation
{
    return Reconciliation::query()->whereDate('date', $date)->first();
}

it('checks only completed UTC days, never today', function () {
    $this->artisan('pixelforge:reconcile', ['--days' => 3])->assertSuccessful();

    expect(Reconciliation::query()->orderBy('date')->pluck('date')->map->toDateString()->all())
        ->toBe(['2026-11-14', '2026-11-15', '2026-11-16']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/usage')
        && $request['start_date'] === '2026-11-14'
        && $request['end_date'] === '2026-11-16');
});

it('checks the last 7 completed days by default', function () {
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    expect(Reconciliation::query()->count())->toBe(7)
        ->and(storedReconciliation('2026-11-10'))->not->toBeNull()
        ->and(storedReconciliation('2026-11-17'))->toBeNull();
});

it('marks a day as matching when cost and count equal Pixelforge usage', function () {
    reconcileCompletedGeneration('2026-11-16 08:00:00', 373_875_000);
    reconcileCompletedGeneration('2026-11-16 21:30:00', 58_200_000);
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 2, 'cost_usd' => '0.432075000']]);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    $day = storedReconciliation('2026-11-16');
    expect($day->our_cost)->toBe(432_075_000)
        ->and($day->our_count)->toBe(2)
        ->and($day->pixelforge_cost)->toBe(432_075_000)
        ->and($day->pixelforge_count)->toBe(2)
        ->and($day->difference)->toBe(0)
        ->and($day->matches())->toBeTrue()
        ->and($day->checked_at->equalTo(now()))->toBeTrue();
});

it('counts a generation on the UTC day Pixelforge completed it, not the day it was created', function () {
    reconcileCompletedGeneration('2026-11-16 00:00:05', 100_000_000, [
        'provider_created_at' => CarbonImmutable::parse('2026-11-15 23:59:50', 'UTC'),
        'created_at' => CarbonImmutable::parse('2026-11-15 23:59:49', 'UTC'),
    ]);
    // One second before midnight still belongs to the 15th.
    reconcileCompletedGeneration('2026-11-15 23:59:59', 7_000_000);
    $this->pixelforge->withUsage([
        '2026-11-15' => ['generations' => 1, 'cost_usd' => '0.007000000'],
        '2026-11-16' => ['generations' => 1, 'cost_usd' => '0.100000000'],
    ]);

    $this->artisan('pixelforge:reconcile', ['--days' => 2])->assertSuccessful();

    expect(storedReconciliation('2026-11-15')->our_cost)->toBe(7_000_000)
        ->and(storedReconciliation('2026-11-15')->matches())->toBeTrue()
        ->and(storedReconciliation('2026-11-16')->our_cost)->toBe(100_000_000)
        ->and(storedReconciliation('2026-11-16')->matches())->toBeTrue();
});

it('includes anomalies in our cost, since Pixelforge billed them', function () {
    reconcileCompletedGeneration('2026-11-16 09:00:00', 100_000_000);
    Generation::factory()->failed()->create([
        'is_anomaly' => true,
        'provider_cost' => 50_000_000,
        'completed_at' => CarbonImmutable::parse('2026-11-16 10:00:00', 'UTC'),
    ]);
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 2, 'cost_usd' => '0.150000000']]);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    expect(storedReconciliation('2026-11-16')->our_cost)->toBe(150_000_000)
        ->and(storedReconciliation('2026-11-16')->our_count)->toBe(2)
        ->and(storedReconciliation('2026-11-16')->matches())->toBeTrue();
});

it('ignores failed generations without a provider cost and unfinished ones', function () {
    reconcileCompletedGeneration('2026-11-16 09:00:00', 100_000_000);
    Generation::factory()->failed()->create(['completed_at' => CarbonImmutable::parse('2026-11-16 10:00:00', 'UTC')]);
    Generation::factory()->processing()->create();
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 1, 'cost_usd' => '0.100000000']]);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    expect(storedReconciliation('2026-11-16')->our_count)->toBe(1)
        ->and(storedReconciliation('2026-11-16')->matches())->toBeTrue();
});

it('stores and logs the difference when the cost differs', function () {
    Log::spy();
    reconcileCompletedGeneration('2026-11-16 09:00:00', 100_000_001);
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 1, 'cost_usd' => '0.100000000']]);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    $day = storedReconciliation('2026-11-16');
    expect($day->difference)->toBe(1)
        ->and($day->pixelforge_cost)->toBe(100_000_000)
        ->and($day->matches())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'usage')
            && $context['date'] === '2026-11-16'
            && $context['difference'] === '0.000000001')
        ->once();
});

it('stores a negative difference when Pixelforge billed more than we recorded', function () {
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 1, 'cost_usd' => '0.250000000']]);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    $day = storedReconciliation('2026-11-16');
    expect($day->our_cost)->toBe(0)
        ->and($day->our_count)->toBe(0)
        ->and($day->difference)->toBe(-250_000_000)
        ->and($day->matches())->toBeFalse();
});

it('flags a day whose cost matches but whose count differs', function () {
    Log::spy();
    reconcileCompletedGeneration('2026-11-16 09:00:00', 100_000_000);
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 2, 'cost_usd' => '0.100000000']]);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    expect(storedReconciliation('2026-11-16')->difference)->toBe(0)
        ->and(storedReconciliation('2026-11-16')->matches())->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
});

it('does not log matching days', function () {
    Log::spy();

    $this->artisan('pixelforge:reconcile', ['--days' => 2])->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
});

it('updates a day it checked before instead of adding a second row', function () {
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 1, 'cost_usd' => '0.100000000']]);
    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();
    expect(storedReconciliation('2026-11-16')->matches())->toBeFalse();

    reconcileCompletedGeneration('2026-11-16 09:00:00', 100_000_000);
    $this->travel(1)->hours();
    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    expect(Reconciliation::query()->count())->toBe(1)
        ->and(storedReconciliation('2026-11-16')->matches())->toBeTrue()
        ->and(storedReconciliation('2026-11-16')->checked_at->equalTo(now()))->toBeTrue();
});

it('keeps sums exact for amounts far beyond float precision', function () {
    // 2^53 + 1 nanodollars cannot be represented as a float.
    reconcileCompletedGeneration('2026-11-16 09:00:00', 9_007_199_254_740_992);
    reconcileCompletedGeneration('2026-11-16 10:00:00', 1);
    $this->pixelforge->withUsage(['2026-11-16' => ['generations' => 2, 'cost_usd' => '9007199.254740993']]);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();

    expect(storedReconciliation('2026-11-16')->our_cost)->toBe(9_007_199_254_740_993)
        ->and(storedReconciliation('2026-11-16')->matches())->toBeTrue();
});

it('fails without storing anything when Pixelforge cannot be asked', function () {
    config(['pixelforge.api_key' => '']);

    $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertFailed();

    expect(Reconciliation::query()->count())->toBe(0);
});

it('refuses a range Pixelforge would reject', function (int $days) {
    $this->artisan('pixelforge:reconcile', ['--days' => $days])->assertFailed();

    expect(Reconciliation::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([0, -1, 93]);
