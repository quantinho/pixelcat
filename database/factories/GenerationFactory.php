<?php

namespace Database\Factories;

use App\Actions\Pricing\CalculateCost;
use App\Actions\Pricing\EstimatePrice;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Generations whose money columns are consistent with CalculateCost and EstimatePrice
 * at Pixelforge's documented rates.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /** The pricing published in the Pixelforge docs (GET /v1/pricing). */
    public const DOCUMENTED_PRICING = [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Waiting,
            'prompt' => 'A ginger cat reading a newspaper, oil painting',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'available_at' => now(),
            // Estimate and request hash are derived from the final attributes below.
            'request_hash' => '',
            'estimate_min' => 0,
            'estimate_likely' => 0,
            'estimate_max' => 0,
            'estimate_rates' => self::DOCUMENTED_PRICING['rates'],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Generation $generation) {
            $estimate = app(EstimatePrice::class)(
                $generation->prompt,
                $generation->size,
                $generation->quality,
                count($generation->reference_images),
                self::pricing(),
            );

            $generation->request_hash = $generation->request_hash !== '' ? $generation->request_hash : hash('sha256', (string) json_encode([
                $generation->prompt, $generation->size->value, $generation->quality->value, $generation->reference_images,
            ]));
            $generation->estimate_min = $generation->estimate_min ?: $estimate->min;
            $generation->estimate_likely = $generation->estimate_likely ?: $estimate->likely;
            $generation->estimate_max = $generation->estimate_max ?: $estimate->max;
        });
    }

    public static function pricing(): Pricing
    {
        return Pricing::fromArray(self::DOCUMENTED_PRICING);
    }

    public function waiting(): static
    {
        return $this->state(['status' => GenerationStatus::Waiting]);
    }

    public function submitting(): static
    {
        return $this->state(['status' => GenerationStatus::Submitting, 'submit_attempts' => 1]);
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
            'rates' => self::DOCUMENTED_PRICING['rates'],
            'submit_attempts' => 1,
            'provider_created_at' => now()->subSeconds(20),
            'last_synced_at' => now()->subSeconds(20),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Processing,
            'started_at' => now()->subSeconds(15),
            'last_synced_at' => now()->subSeconds(15),
        ]);
    }

    /**
     * Succeeded with usage at the estimate's "likely" token counts.
     */
    public function succeeded(): static
    {
        return $this->processing()
            ->state(['status' => GenerationStatus::Succeeded])
            ->afterMaking(function (Generation $generation) {
                $pricing = self::pricing();
                $reasoning = $pricing->reasoningRange($generation->quality);
                $usage = [
                    'input_text_tokens' => max(1, (int) ceil(mb_strlen($generation->prompt) / 4)),
                    'input_image_tokens' => 765 * count($generation->reference_images),
                    'output_tokens' => $pricing->outputTokens($generation->quality, $generation->size),
                    'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
                ];

                $cost = app(CalculateCost::class);
                $providerCost = $cost->providerCost($usage, self::DOCUMENTED_PRICING['rates']);

                $generation->fill($usage + [
                    'provider_cost' => $providerCost,
                    'charged' => $cost->userPrice($providerCost),
                    'completed_at' => now(),
                    'image_url' => "https://pixelforge.test/images/{$generation->pixelforge_id}.png",
                    'image_url_expires_at' => now()->addDay(),
                    'last_synced_at' => now(),
                ]);
            });
    }

    public function failed(string $code = 'generation_failed'): static
    {
        return $this->processing()->state(fn () => [
            'status' => GenerationStatus::Failed,
            'error_code' => $code,
            'error_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => now(),
            'last_synced_at' => now(),
        ]);
    }
}
