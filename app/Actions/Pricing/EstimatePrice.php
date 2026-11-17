<?php

namespace App\Actions\Pricing;

use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\Pricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Two parts of a price are only known once a generation finishes: the exact prompt token
 * count (within ±15% of the estimate) and the reasoning tokens (a range per quality).
 * The estimate is therefore a range. The text range is rounded outwards, so it is never
 * narrower than the documented ±15% and the held maximum always covers the real price.
 */
final class EstimatePrice
{
    public function __construct(private CalculateCost $calculateCost) {}

    public function __invoke(string $prompt, Size $size, Quality $quality, int $referenceCount, Pricing $pricing): PriceEstimate
    {
        $variance = BigDecimal::of($pricing->textTokenVariance);
        $text = max(1, intdiv(mb_strlen($prompt) + $pricing->charactersPerTextToken - 1, $pricing->charactersPerTextToken));
        $textMin = BigDecimal::one()->minus($variance)->multipliedBy($text)->toScale(0, RoundingMode::Floor)->toInt();
        $textMax = BigDecimal::one()->plus($variance)->multipliedBy($text)->toScale(0, RoundingMode::Ceiling)->toInt();

        $images = $pricing->tokensPerReferenceImage * $referenceCount;
        $output = $pricing->outputTokens($quality, $size);
        $reasoning = $pricing->reasoningRange($quality);
        $reasoningLikely = intdiv($reasoning['min'] + $reasoning['max'], 2);

        $tokens = [
            'input_text_tokens' => [$textMin, $text, $textMax],
            'input_image_tokens' => [$images, $images, $images],
            'output_tokens' => [$output, $output, $output],
            'reasoning_tokens' => [$reasoning['min'], $reasoningLikely, $reasoning['max']],
        ];

        $usage = fn (int $index) => [
            'input_text_tokens' => $tokens['input_text_tokens'][$index],
            'input_image_tokens' => $tokens['input_image_tokens'][$index],
            'output_tokens' => $tokens['output_tokens'][$index],
            'reasoning_tokens' => $tokens['reasoning_tokens'][$index],
        ];

        $breakdown = [];
        foreach ($tokens as $type => [$min, , $max]) {
            $breakdown[] = [
                'type' => $type,
                'min_tokens' => $min,
                'max_tokens' => $max,
                'min_cost' => $this->calculateCost->providerCost($this->only($type, $min), $pricing->rates),
                'max_cost' => $this->calculateCost->providerCost($this->only($type, $max), $pricing->rates),
            ];
        }

        $price = fn (int $index) => $this->calculateCost->userPrice(
            $this->calculateCost->providerCost($usage($index), $pricing->rates),
        );

        return new PriceEstimate(
            min: $price(0),
            likely: $price(1),
            max: $price(2),
            breakdown: $breakdown,
            markupPercent: (int) config('pixelcat.markup_percent'),
            pricingVersion: $pricing->version(),
        );
    }

    /**
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    private function only(string $type, int $count): array
    {
        return [
            'input_text_tokens' => $type === 'input_text_tokens' ? $count : 0,
            'input_image_tokens' => $type === 'input_image_tokens' ? $count : 0,
            'output_tokens' => $type === 'output_tokens' ? $count : 0,
            'reasoning_tokens' => $type === 'reasoning_tokens' ? $count : 0,
        ];
    }
}
