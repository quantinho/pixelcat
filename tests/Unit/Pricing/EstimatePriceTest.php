<?php

use App\Actions\Pricing\CalculateCost;
use App\Actions\Pricing\EstimatePrice;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\Pricing;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\FakePixelforge;

dataset('qualities', ['low', 'medium', 'high']);
dataset('sizes', ['1024x1024', '1536x1024', '1024x1536']);

beforeEach(function () {
    config(['pixelcat.markup_percent' => 25]);
    $this->pricing = Pricing::fromArray(FakePixelforge::pricingJson());
    $this->estimate = fn (string $prompt, Size $size = Size::Square, Quality $quality = Quality::Medium, int $references = 0, ?Pricing $pricing = null): object => app(EstimatePrice::class)($prompt, $size, $quality, $references, $pricing ?? $this->pricing);
});

/**
 * The provider-cost breakdown row for one token type.
 *
 * @return array{type: string, min_tokens: int, max_tokens: int, min_cost: int, max_cost: int}
 */
function breakdownRow(object $estimate, string $type): array
{
    foreach ($estimate->breakdown as $row) {
        if ($row['type'] === $type) {
            return $row;
        }
    }

    throw new RuntimeException("No breakdown row for [{$type}].");
}

/** @return array{0: int, 1: int} the text token range the estimate used */
function textRange(object $estimate): array
{
    $row = breakdownRow($estimate, 'input_text_tokens');

    return [$row['min_tokens'], $row['max_tokens']];
}

describe('Pricing', function () {
    it('reads the token rules from GET /v1/pricing', function () {
        expect($this->pricing->rates)->toBe(FakePixelforge::RATES)
            ->and($this->pricing->charactersPerTextToken)->toBe(4)
            ->and($this->pricing->textTokenVariancePercent)->toBe(15)
            ->and($this->pricing->tokensPerReferenceImage)->toBe(765)
            ->and($this->pricing->outputTokens(Quality::High, Size::Landscape))->toBe(6450)
            ->and($this->pricing->outputTokens(Quality::Low, Size::Square))->toBe(280)
            ->and($this->pricing->reasoningRange(Quality::Medium))->toBe(['min' => 200, 'max' => 2000]);
    });

    it('has a version that changes only when the rates change', function () {
        $reordered = FakePixelforge::pricingJson();
        $reordered['rates'] = array_reverse($reordered['rates'], true);

        $changed = Pricing::fromArray(FakePixelforge::pricingJson(['output_tokens' => '41.00']));

        expect($this->pricing->version())->toMatch('/^[0-9a-f]{64}$/')
            ->and(Pricing::fromArray($reordered)->version())->toBe($this->pricing->version())
            ->and($changed->version())->not->toBe($this->pricing->version());
    });
});

describe('WorkedExample estimate', function () {
    beforeEach(function () {
        $this->workedExample = ($this->estimate)(str_repeat('a', 400), Size::Landscape, Quality::High, 2);
    });

    it('has the docs maximum as its top and the matching minimum and likely prices', function () {
        // min: 85 text, 1,530 image, 6,450 output, 1,000 reasoning = 0.286225000 → × 1.25
        // likely: 100 text, 4,500 reasoning = 0.330050000 → × 1.25
        // max: 115 text, 8,000 reasoning = 0.373875000 → × 1.25
        expect($this->workedExample->min)->toBe(357_781_250)
            ->and($this->workedExample->likely)->toBe(412_562_500)
            ->and($this->workedExample->max)->toBe(FakePixelforge::WORKED_EXAMPLE_USER_PRICE)
            ->and($this->workedExample->markupPercent)->toBe(25)
            ->and($this->workedExample->pricingVersion)->toBe($this->pricing->version());
    });

    it('breaks the provider cost down per token type', function () {
        expect(breakdownRow($this->workedExample, 'input_text_tokens'))->toMatchArray([
            'min_tokens' => 85, 'max_tokens' => 115, 'min_cost' => 425_000, 'max_cost' => 575_000,
        ])->and(breakdownRow($this->workedExample, 'input_image_tokens'))->toMatchArray([
            'min_tokens' => 1530, 'max_tokens' => 1530, 'min_cost' => 15_300_000, 'max_cost' => 15_300_000,
        ])->and(breakdownRow($this->workedExample, 'output_tokens'))->toMatchArray([
            'min_tokens' => 6450, 'max_tokens' => 6450, 'min_cost' => 258_000_000, 'max_cost' => 258_000_000,
        ])->and(breakdownRow($this->workedExample, 'reasoning_tokens'))->toMatchArray([
            'min_tokens' => 1000, 'max_tokens' => 8000, 'min_cost' => 12_500_000, 'max_cost' => 100_000_000,
        ]);
    });
});

describe('text tokens', function () {
    it('uses floor(0.85e) … ceil(1.15e) around e = ceil(chars / 4)', function (int $chars, int $min, int $max) {
        expect(textRange(($this->estimate)(str_repeat('x', $chars))))->toBe([$min, $max]);
    })->with([
        'e = 1' => [4, 0, 2],
        'e = 1 from one character' => [1, 0, 2],
        'e = 2 from five characters' => [5, 1, 3],
        'e = 7' => [28, 5, 9],
        'e = 20' => [80, 17, 23],
        'e = 100' => [400, 85, 115],
        'e = 1,000 (4,000 characters)' => [4000, 850, 1150],
    ]);

    it('treats an empty prompt as one token', function () {
        expect(textRange(($this->estimate)('')))->toBe([0, 2]);
    });

    it('counts Unicode characters, not bytes', function (string $prompt, array $range) {
        expect(textRange(($this->estimate)($prompt)))->toBe($range);
    })->with([
        // 10 characters (20 bytes) → e = 3.
        'Ukrainian letters' => [str_repeat('ї', 10), [2, 4]],
        // 23 characters → e = 6.
        'Ukrainian sentence' => ['Кіт спить на клавіатурі', [5, 7]],
        // 8 characters (32 bytes) → e = 2.
        'emoji' => [str_repeat('🐱', 8), [1, 3]],
    ]);

    it('counts test keywords as prompt text', function () {
        // "a cat" is 5 characters (e = 2); "a cat [fail]" is 12 (e = 3).
        expect(textRange(($this->estimate)('a cat')))->toBe([1, 3])
            ->and(textRange(($this->estimate)('a cat [fail]')))->toBe([2, 4]);
    });
});

describe('other token types', function () {
    it('adds 765 tokens per reference image', function (int $references) {
        $row = breakdownRow(($this->estimate)('a cat', references: $references), 'input_image_tokens');

        expect($row['min_tokens'])->toBe(765 * $references)
            ->and($row['max_tokens'])->toBe(765 * $references)
            ->and($row['min_cost'])->toBe(765 * $references * 10_000);
    })->with([0, 1, 2, 3, 4]);

    it('uses the exact output tokens for each quality and size', function (string $quality, string $size) {
        [$quality, $size] = [Quality::from($quality), Size::from($size)];
        $row = breakdownRow(($this->estimate)('a cat', $size, $quality), 'output_tokens');
        $tokens = FakePixelforge::OUTPUT_TOKENS[$quality->value][$size->value];

        expect($row['min_tokens'])->toBe($tokens)->and($row['max_tokens'])->toBe($tokens);
    })->with('qualities')->with('sizes');

    it('uses the reasoning range for each quality', function (string $quality) {
        $quality = Quality::from($quality);
        $row = breakdownRow(($this->estimate)('a cat', quality: $quality), 'reasoning_tokens');

        expect([$row['min_tokens'], $row['max_tokens']])
            ->toBe(array_values(FakePixelforge::REASONING_TOKENS[$quality->value]));
    })->with('qualities');
});

describe('min, likely and max', function () {
    it('takes the reasoning midpoint and e text tokens as most likely', function () {
        // low, 1024x1024, e = 1: likely = 1 text + 280 output + 250 reasoning.
        $estimate = ($this->estimate)('abcd', Size::Square, Quality::Low);

        expect($estimate->min)->toBe(14_000_000)      // 280 output = 0.011200000 → × 1.25
            ->and($estimate->likely)->toBe(17_912_500) // 0.014330000 → × 1.25
            ->and($estimate->max)->toBe(21_825_000);   // 2 text + 500 reasoning = 0.017460000 → × 1.25
    });

    it('rounds each of min, likely and max up to the next nanodollar', function () {
        // At 1 nanodollar per token: min 280 → 350; likely 531 → 663.75 → 664; max 782 → 977.5 → 978.
        $pricing = Pricing::fromArray(FakePixelforge::pricingJson([
            'input_text_tokens' => '0.001',
            'input_image_tokens' => '0.001',
            'output_tokens' => '0.001',
            'reasoning_tokens' => '0.001',
        ]));

        $estimate = ($this->estimate)('abcd', Size::Square, Quality::Low, 0, $pricing);

        expect([$estimate->min, $estimate->likely, $estimate->max])->toBe([350, 664, 978]);
    });

    it('keeps min ≤ likely ≤ max for every quality and size', function (string $quality, string $size) {
        $estimate = ($this->estimate)(str_repeat('a', 401), Size::from($size), Quality::from($quality), 3);

        expect($estimate->min)->toBeLessThanOrEqual($estimate->likely)
            ->and($estimate->likely)->toBeLessThanOrEqual($estimate->max)
            ->and($estimate->min)->toBeGreaterThan(0);
    })->with('qualities')->with('sizes');
});

it('EstimateRange: the actual price lies within min…max for 1,000 random requests at unchanged rates (SC-002)', function () {
    $random = new Randomizer(new Mt19937(20261117));
    $calculate = app(CalculateCost::class);
    $pieces = ['a', 'b', 'c', ' ', '.', 'ї', 'Ж', '猫', '🐱', 'é', '[fail]', '[slow]', '[succeed]'];
    $sizes = Size::cases();
    $qualities = Quality::cases();

    foreach (range(1, 1000) as $case) {
        // A prompt of 1–4,000 characters mixing ASCII, Cyrillic, CJK, emoji and keywords.
        $target = $random->getInt(1, 4000);
        $prompt = '';
        while (mb_strlen($prompt) < $target) {
            $prompt .= $pieces[$random->getInt(0, count($pieces) - 1)];
        }
        $prompt = mb_substr($prompt, 0, $target);

        $size = $sizes[$random->getInt(0, count($sizes) - 1)];
        $quality = $qualities[$random->getInt(0, count($qualities) - 1)];
        $references = $random->getInt(0, 4);

        $estimate = ($this->estimate)($prompt, $size, $quality, $references);

        // Usage within the documented ranges: text within ±15% of e (either rounding),
        // exact image and output tokens, reasoning within the quality's range.
        $e = max(1, intdiv(mb_strlen($prompt) + 3, 4));
        $text = [intdiv($e * 85, 100), intdiv($e * 115 + 99, 100)];
        $reasoning = FakePixelforge::REASONING_TOKENS[$quality->value];
        $fixed = [
            'input_image_tokens' => 765 * $references,
            'output_tokens' => FakePixelforge::OUTPUT_TOKENS[$quality->value][$size->value],
        ];

        $usages = [
            'lowest' => ['input_text_tokens' => $text[0], ...$fixed, 'reasoning_tokens' => $reasoning['min']],
            'highest' => ['input_text_tokens' => $text[1], ...$fixed, 'reasoning_tokens' => $reasoning['max']],
            'random' => [
                'input_text_tokens' => $random->getInt(...$text),
                ...$fixed,
                'reasoning_tokens' => $random->getInt($reasoning['min'], $reasoning['max']),
            ],
        ];

        foreach ($usages as $kind => $usage) {
            $actual = $calculate->userPrice($calculate->providerCost($usage, FakePixelforge::RATES));

            expect($actual)
                ->toBeGreaterThanOrEqual($estimate->min, "case {$case} ({$kind}): below the estimate minimum")
                ->toBeLessThanOrEqual($estimate->max, "case {$case} ({$kind}): above the estimate maximum");
        }
    }
});
