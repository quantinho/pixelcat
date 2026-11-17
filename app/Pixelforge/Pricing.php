<?php

namespace App\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Pixelforge's published pricing (GET /v1/pricing). Rates are USD per 1M tokens,
 * kept as exact decimal strings.
 */
final readonly class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public int $textTokenVariancePercent,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $rates = [];
        foreach (self::TOKEN_TYPES as $type) {
            $rate = $json['rates'][$type] ?? null;
            if (! is_string($rate)) {
                throw new InvalidArgumentException("Pricing is missing the [{$type}] rate.");
            }
            $rates[$type] = $rate;
        }

        $tokens = $json['tokens'];

        // 0.15 arrives as a JSON number; keep it exact. The whole-percent view rounds up,
        // so it is never narrower than Pixelforge's real variance.
        $variance = BigDecimal::of((string) $tokens['text_token_variance']);
        $variancePercent = $variance->multipliedBy(100)->toScale(0, RoundingMode::Ceiling)->toInt();

        return new self(
            rates: $rates,
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVariancePercent: $variancePercent,
            textTokenVariance: (string) $variance,
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    public function outputTokens(Quality $quality, Size $size): int
    {
        return (int) $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRange(Quality $quality): array
    {
        $range = $this->reasoningTokens[$quality->value];

        return ['min' => (int) $range['min'], 'max' => (int) $range['max']];
    }

    /**
     * Changes whenever any rate changes; the form sends it back so a submission
     * priced with old rates can be refused.
     */
    public function version(): string
    {
        return hash('sha256', (string) json_encode($this->rates));
    }
}
