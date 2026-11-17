<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Money is integer nanodollars (1 USD = 1,000,000,000). Pixelforge prices are exact to
 * 9 decimals, so every amount fits an int exactly and sums are plain integer sums.
 * Floats never touch an amount: parsing and multiplication go through brick/math.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /**
     * Parse an exact USD decimal string, e.g. "0.373875000" → 373_875_000.
     */
    public static function fromDecimal(string $usd): int
    {
        if (preg_match('/^-?\d+(\.\d{1,9})?$/', $usd) !== 1) {
            throw new InvalidArgumentException("Not an exact USD amount with at most 9 decimals: [{$usd}].");
        }

        try {
            return BigDecimal::of($usd)
                ->multipliedBy(self::NANOS_PER_DOLLAR)
                ->toScale(0)
                ->toInt();
        } catch (MathException $e) {
            throw new InvalidArgumentException("USD amount [{$usd}] is out of range.", previous: $e);
        }
    }

    /**
     * Format nanodollars as an exact 9-decimal USD string, e.g. 373_875_000 → "0.373875000".
     */
    public static function toDecimal(int $nanos): string
    {
        return (string) BigDecimal::ofUnscaledValue($nanos, 9);
    }

    /**
     * Format for people: dollars rounded half-up to 4 decimals, e.g. "$0.3739", "-$0.0100".
     */
    public static function display(int $nanos): string
    {
        $rounded = BigDecimal::ofUnscaledValue(abs($nanos), 9)->toScale(4, RoundingMode::HalfUp);

        return ($nanos < 0 ? '-$' : '$').$rounded;
    }

    /**
     * Format a round amount for copy, e.g. the welcome credit: "$2.00". Never for prices,
     * which use display() or the exact value.
     */
    public static function displayCents(int $nanos): string
    {
        $rounded = BigDecimal::ofUnscaledValue(abs($nanos), 9)->toScale(2, RoundingMode::HalfUp);

        return ($nanos < 0 ? '-$' : '$').$rounded;
    }

    /**
     * Add a percentage markup, rounding up to the next nanodollar.
     * This is the only place money is rounded.
     */
    public static function applyMarkup(int $nanos, int $percent): int
    {
        return BigDecimal::of($nanos)
            ->multipliedBy(100 + $percent)
            ->dividedBy(100, 0, RoundingMode::Ceiling)
            ->toInt();
    }
}
