<?php

namespace App\Actions\Pricing;

/**
 * An estimated user price. min, likely and max are user nanodollars (markup included);
 * the breakdown is provider cost per token type.
 */
final readonly class PriceEstimate
{
    /**
     * @param  list<array{type: string, min_tokens: int, max_tokens: int, min_cost: int, max_cost: int}>  $breakdown
     */
    public function __construct(
        public int $min,
        public int $likely,
        public int $max,
        public array $breakdown,
        public int $markupPercent,
        public string $pricingVersion,
    ) {}
}
