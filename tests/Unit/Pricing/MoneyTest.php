<?php

use App\Billing\Money;

/*
 * Money is integer nanodollars (constitution I). These tests use values a float cannot hold
 * exactly, so an implementation that goes through float fails them.
 */

describe('fromDecimal', function () {
    it('parses a decimal USD string to nanodollars', function (string $usd, int $nanos) {
        expect(Money::fromDecimal($usd))->toBe($nanos);
    })->with([
        'worked example' => ['0.373875000', 373_875_000],
        'user price' => ['0.467343750', 467_343_750],
        'one nanodollar' => ['0.000000001', 1],
        'zero' => ['0', 0],
        'zero with decimals' => ['0.000000000', 0],
        'whole dollars' => ['2', 2_000_000_000],
        'fewer than 9 decimals' => ['12.5', 12_500_000_000],
        'two decimals' => ['0.01', 10_000_000],
        'more digits than a float keeps' => ['12345678.123456789', 12_345_678_123_456_789],
        'largest bigint' => ['9223372036.854775807', PHP_INT_MAX],
        'float trap 0.1' => ['0.100000000', 100_000_000],
    ]);

    it('adds parsed amounts exactly where floats would drift', function () {
        expect(Money::fromDecimal('0.1') + Money::fromDecimal('0.2'))->toBe(Money::fromDecimal('0.3'));
    });

    it('rejects more than 9 decimals instead of rounding', function (string $usd) {
        expect(fn () => Money::fromDecimal($usd))->toThrow(Exception::class);
    })->with([
        'ten decimals' => ['0.0000000001'],
        'ten decimals, trailing zero' => ['0.3738750001'],
        'float noise' => ['0.30000000000000004'],
    ]);

    it('rejects input that is not a plain decimal', function (string $usd) {
        expect(fn () => Money::fromDecimal($usd))->toThrow(Exception::class);
    })->with([
        'empty' => [''],
        'letters' => ['abc'],
        'scientific notation' => ['1e-9'],
        'comma separator' => ['1,00'],
        'currency sign' => ['$1.00'],
    ]);
});

describe('toDecimal', function () {
    it('formats nanodollars with exactly 9 decimals', function (int $nanos, string $usd) {
        expect(Money::toDecimal($nanos))->toBe($usd);
    })->with([
        'worked example' => [373_875_000, '0.373875000'],
        'user price' => [467_343_750, '0.467343750'],
        'zero' => [0, '0.000000000'],
        'one nanodollar' => [1, '0.000000001'],
        'whole dollars' => [2_000_000_000, '2.000000000'],
        'negative' => [-10_000_000, '-0.010000000'],
        'negative under a dollar' => [-467_343_750, '-0.467343750'],
        'largest bigint' => [PHP_INT_MAX, '9223372036.854775807'],
    ]);

    it('round-trips with fromDecimal', function (int $nanos) {
        expect(Money::fromDecimal(Money::toDecimal($nanos)))->toBe($nanos);
    })->with([1, 999_999_999, 1_000_000_001, 12_345_678_123_456_789]);
});

describe('display', function () {
    it('rounds half-up to 4 decimals with a dollar sign', function (int $nanos, string $display) {
        expect(Money::display($nanos))->toBe($display);
    })->with([
        'worked example' => [373_875_000, '$0.3739'],
        'user price' => [467_343_750, '$0.4673'],
        'zero' => [0, '$0.0000'],
        'exactly half rounds up' => [50_000, '$0.0001'],
        'just under half rounds down' => [49_999, '$0.0000'],
        'carry into the dollars' => [999_950_000, '$1.0000'],
        'whole dollars' => [2_000_000_000, '$2.0000'],
        'cents' => [10_000_000, '$0.0100'],
        'negative' => [-10_000_000, '-$0.0100'],
    ]);
});

describe('displayCents', function () {
    it('rounds half-up to 2 decimals for round amounts in copy', function (int $nanos, string $display) {
        expect(Money::displayCents($nanos))->toBe($display);
    })->with([
        'welcome credit' => [2_000_000_000, '$2.00'],
        'half a cent rounds up' => [5_000_000, '$0.01'],
        'negative' => [-10_000_000, '-$0.01'],
    ]);
});

describe('perTokenNanos', function () {
    it('converts a rate per 1M tokens to nanodollars per token', function (string $rate, int $nanos) {
        expect(Money::perTokenNanos($rate))->toBe($nanos);
    })->with([
        'input text' => ['5.00', 5_000],
        'input image' => ['10.00', 10_000],
        'output' => ['40.00', 40_000],
        'reasoning' => ['12.50', 12_500],
        'no decimals' => ['40', 40_000],
        'one nanodollar per token' => ['0.001', 1],
        'zero' => ['0.00', 0],
    ]);

    it('rejects a rate that is not a whole nanodollar per token', function (string $rate) {
        expect(fn () => Money::perTokenNanos($rate))->toThrow(Exception::class);
    })->with([
        'a tenth of a nanodollar' => ['0.0001'],
        'one and a half nanodollars' => ['0.0015'],
        'not a number' => ['twelve'],
    ]);
});

describe('applyMarkup', function () {
    it('multiplies by (100 + percent) / 100 and rounds up to the next nanodollar', function (int $nanos, int $percent, int $expected) {
        expect(Money::applyMarkup($nanos, $percent))->toBe($expected);
    })->with([
        'worked example, exact' => [373_875_000, 25, 467_343_750],
        'zero' => [0, 25, 0],
        '1.25 rounds up to 2' => [1, 25, 2],
        '2.5 rounds up to 3' => [2, 25, 3],
        '3.75 rounds up to 4' => [3, 25, 4],
        '5 stays 5' => [4, 25, 5],
        'no markup' => [123_456_789, 0, 123_456_789],
        'double' => [123_456_789, 100, 246_913_578],
        'beyond float precision' => [1_000_000_000_000_000_001, 25, 1_250_000_000_000_000_002],
    ]);

    it('never rounds down', function () {
        foreach (range(1, 400) as $nanos) {
            $marked = Money::applyMarkup($nanos, 25);

            expect($marked * 100)->toBeGreaterThanOrEqual($nanos * 125)
                ->and(($marked - 1) * 100)->toBeLessThan($nanos * 125);
        }
    });
});
