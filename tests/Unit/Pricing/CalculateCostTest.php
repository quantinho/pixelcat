<?php

use App\Actions\Pricing\CalculateCost;
use App\Billing\Money;
use Tests\Support\FakePixelforge;

/** @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int} */
function usageOf(int $text = 0, int $image = 0, int $output = 0, int $reasoning = 0): array
{
    return [
        'input_text_tokens' => $text,
        'input_image_tokens' => $image,
        'output_tokens' => $output,
        'reasoning_tokens' => $reasoning,
    ];
}

beforeEach(function () {
    config(['pixelcat.markup_percent' => 25]);
    $this->calculate = app(CalculateCost::class);
});

describe('WorkedExample (SC-001)', function () {
    it('costs exactly 0.373875000 USD at Pixelforge', function () {
        $cost = $this->calculate->providerCost(FakePixelforge::WORKED_EXAMPLE_USAGE, FakePixelforge::RATES);

        expect($cost)->toBe(373_875_000)
            ->and(Money::toDecimal($cost))->toBe('0.373875000');
    });

    it('charges the user exactly 0.467343750 USD', function () {
        $cost = $this->calculate->providerCost(FakePixelforge::WORKED_EXAMPLE_USAGE, FakePixelforge::RATES);
        $price = $this->calculate->userPrice($cost);

        expect($price)->toBe(467_343_750)
            ->and(Money::toDecimal($price))->toBe('0.467343750');
    });

    it('matches each line of the docs table', function (array $usage, int $nanos) {
        expect($this->calculate->providerCost($usage, FakePixelforge::RATES))->toBe($nanos);
    })->with([
        'input text 115 × $5.00' => [usageOf(text: 115), 575_000],
        'input image 1,530 × $10.00' => [usageOf(image: 1530), 15_300_000],
        'output 6,450 × $40.00' => [usageOf(output: 6450), 258_000_000],
        'reasoning 8,000 × $12.50' => [usageOf(reasoning: 8000), 100_000_000],
    ]);
});

describe('providerCost', function () {
    it('is zero for zero usage', function () {
        expect($this->calculate->providerCost(usageOf(), FakePixelforge::RATES))->toBe(0);
    });

    it('handles rates with 2 decimals and odd token counts exactly', function () {
        // 3 × 12.50 = 37.5 USD per 1M tokens → 37,500 nanodollars.
        expect($this->calculate->providerCost(usageOf(reasoning: 3), FakePixelforge::RATES))->toBe(37_500)
            // 7 × 0.25 per 1M = 1,750 nanodollars; 1 × 0.37 per 1M = 370.
            ->and($this->calculate->providerCost(usageOf(text: 7, image: 1), [
                ...FakePixelforge::RATES, 'input_text_tokens' => '0.25', 'input_image_tokens' => '0.37',
            ]))->toBe(2_120);
    });

    it('accepts rates written without decimals or with one', function () {
        $rates = ['input_text_tokens' => '5', 'input_image_tokens' => '10', 'output_tokens' => '40', 'reasoning_tokens' => '12.5'];

        expect($this->calculate->providerCost(FakePixelforge::WORKED_EXAMPLE_USAGE, $rates))->toBe(373_875_000);
    });

    it('uses the rates it is given, not the current price list', function () {
        $doubled = [
            'input_text_tokens' => '10.00',
            'input_image_tokens' => '20.00',
            'output_tokens' => '80.00',
            'reasoning_tokens' => '25.00',
        ];

        expect($this->calculate->providerCost(FakePixelforge::WORKED_EXAMPLE_USAGE, $doubled))->toBe(747_750_000);
    });

    it('stays exact for large token counts', function () {
        // 123,456,789 output tokens × $40.00 per 1M = 4,938.27156 USD.
        expect($this->calculate->providerCost(usageOf(output: 123_456_789), FakePixelforge::RATES))
            ->toBe(4_938_271_560_000);
    });

    it('throws when the cost is not a whole number of nanodollars', function (array $usage, array $rates) {
        expect(fn () => $this->calculate->providerCost($usage, [...FakePixelforge::RATES, ...$rates]))
            ->toThrow(Exception::class);
    })->with([
        // 1 × 0.0001 USD per 1M = 0.1 nanodollar.
        'a tenth of a nanodollar' => [usageOf(text: 1), ['input_text_tokens' => '0.0001']],
        // 1 × 5.0005 USD per 1M = 5,000.5 nanodollars.
        'half a nanodollar' => [usageOf(reasoning: 1), ['reasoning_tokens' => '5.0005']],
    ]);
});

describe('userPrice', function () {
    it('adds the 25% markup and rounds up to the next nanodollar', function (int $cost, int $price) {
        expect($this->calculate->userPrice($cost))->toBe($price);
    })->with([
        'exact' => [373_875_000, 467_343_750],
        'zero' => [0, 0],
        '1.25 → 2' => [1, 2],
        '3.75 → 4' => [3, 4],
        '4 → 5' => [4, 5],
        '1,250,001.25 → 1,250,002' => [1_000_001, 1_250_002],
    ]);

    it('reads the markup from config', function () {
        config(['pixelcat.markup_percent' => 0]);
        expect($this->calculate->userPrice(373_875_000))->toBe(373_875_000);

        config(['pixelcat.markup_percent' => 100]);
        expect($this->calculate->userPrice(373_875_000))->toBe(747_750_000);
    });

    it('is always at least provider cost × 1.25 and less than one nanodollar above it', function () {
        foreach ([1, 2, 3, 7, 99, 575_000, 286_225_001, 999_999_999] as $cost) {
            $price = $this->calculate->userPrice($cost);

            expect($price * 4)->toBeGreaterThanOrEqual($cost * 5)
                ->and(($price - 1) * 4)->toBeLessThan($cost * 5);
        }
    });
});
