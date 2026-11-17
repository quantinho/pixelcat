<?php

namespace App\Actions\Pricing;

use App\Billing\Money;
use App\Pixelforge\Pricing;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Provider cost = Σ tokens × rate over the four token types, using the rates stored on the
 * generation (fixed by Pixelforge at creation). User price adds the markup, rounded up.
 */
final class CalculateCost
{
    /**
     * Exact provider cost in nanodollars. Rates are USD per 1M tokens, so n tokens cost
     * n × rate × 1000 nanodollars; a result that is not a whole nanodollar is an error,
     * never something to round (spec "Money Rules").
     *
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}  $usage
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public function providerCost(array $usage, array $rates): int
    {
        $nanosPerMillionTokens = Money::NANOS_PER_DOLLAR / 1_000_000;
        $cost = BigDecimal::zero();

        foreach (Pricing::TOKEN_TYPES as $type) {
            $cost = $cost->plus(
                BigDecimal::of($rates[$type])->multipliedBy($usage[$type])->multipliedBy($nanosPerMillionTokens),
            );
        }

        try {
            return $cost->toScale(0)->toInt();
        } catch (MathException $e) {
            throw new InvalidArgumentException("Provider cost [{$cost}] is not a whole number of nanodollars.", previous: $e);
        }
    }

    public function userPrice(int $providerCost): int
    {
        return Money::applyMarkup($providerCost, (int) config('pixelcat.markup_percent'));
    }
}
