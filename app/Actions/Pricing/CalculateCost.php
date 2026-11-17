<?php

namespace App\Actions\Pricing;

use App\Billing\Money;
use App\Pixelforge\Pricing;

/**
 * Provider cost = Σ tokens × rate over the four token types, using the rates stored on the
 * generation (fixed by Pixelforge at creation). User price adds the markup, rounded up.
 */
final class CalculateCost
{
    /**
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}  $usage
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public function providerCost(array $usage, array $rates): int
    {
        $cost = 0;

        foreach (Pricing::TOKEN_TYPES as $type) {
            $cost += $usage[$type] * Money::perTokenNanos($rates[$type]);
        }

        return $cost;
    }

    public function userPrice(int $providerCost): int
    {
        return Money::applyMarkup($providerCost, (int) config('pixelcat.markup_percent'));
    }
}
