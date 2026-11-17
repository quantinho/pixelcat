<?php

use App\Enums\GenerationStatus;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\MoneyResource;
use App\Models\Generation;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * The resource as the frontend receives it (JSON, nested resources resolved).
 *
 * @return array<string, mixed>
 */
function generationJson(Generation $generation): array
{
    return json_decode(json_encode(GenerationResource::make($generation), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
}

describe('status', function () {
    it('has a label, a rank and finality for every status', function (string $status, string $label, int $rank, bool $final) {
        $generation = Generation::factory()->for($this->user)->{$status}()->create();

        expect(generationJson($generation))->toMatchArray([
            'status' => $status,
            'status_label' => $label,
            'is_final' => $final,
        ]);

        $enum = GenerationStatus::from($status);
        expect($enum->label())->toBe($label)
            ->and($enum->rank())->toBe($rank)
            ->and($enum->isFinal())->toBe($final);
    })->with([
        'waiting' => ['waiting', 'Waiting for a free slot', 0, false],
        'submitting' => ['submitting', 'Submitting', 1, false],
        'queued' => ['queued', 'Queued', 2, false],
        'processing' => ['processing', 'Generating', 3, false],
        'succeeded' => ['succeeded', 'Done', 4, true],
        'failed' => ['failed', 'Failed', 4, true],
    ]);

    it('passes the request fields through', function () {
        $generation = Generation::factory()->for($this->user)->queued()->create([
            'prompt' => 'Кіт спить 🐱',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
        ]);

        expect(generationJson($generation))->toMatchArray([
            'id' => $generation->id,
            'prompt' => 'Кіт спить 🐱',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
        ]);
    });
});

describe('errors (SC-006)', function () {
    it('maps every failure code to its user-facing message, never the raw one', function (string $code, string $message) {
        $generation = Generation::factory()->for($this->user)->failed($code)->create([
            'error_code' => $code,
            'error_message' => 'Raw provider text that users must not see',
        ]);

        expect(generationJson($generation)['error'])->toBe(['code' => $code, 'message' => $message]);
    })->with([
        'content_policy_violation' => ['content_policy_violation', "This prompt was rejected. You weren't charged."],
        'overloaded (retries used up)' => ['overloaded', "Pixelforge is too busy right now. You weren't charged."],
        'rate_limit_exceeded (retries used up)' => ['rate_limit_exceeded', "Pixelforge is too busy right now. You weren't charged."],
        'daily_limit_exceeded' => ['daily_limit_exceeded', "Pixelforge's daily limit is reached. You weren't charged."],
        'validation_failed' => ['validation_failed', "Something went wrong on our side. You weren't charged."],
        'idempotency_key_reused' => ['idempotency_key_reused', "Something went wrong on our side. You weren't charged."],
        'invalid_api_key' => ['invalid_api_key', "Something went wrong on our side. You weren't charged."],
        'generation_failed' => ['generation_failed', "The model couldn't draw this one. You weren't charged."],
        'internal_error' => ['internal_error', "Pixelforge had a problem. You weren't charged."],
        'waited_too_long' => ['waited_too_long', "Pixelforge is too busy right now. You weren't charged."],
        'unknown code' => ['something_new', "Something went wrong. You weren't charged."],
    ]);

    it('has no error unless the generation failed', function (string $status) {
        $generation = Generation::factory()->for($this->user)->{$status}()->create();

        expect(generationJson($generation)['error'])->toBeNull();
    })->with(['waiting', 'submitting', 'queued', 'processing', 'succeeded']);
});

describe('money', function () {
    beforeEach(function () {
        // The worked example's estimate; the actual price landed on "likely".
        $this->generation = Generation::factory()->for($this->user)->succeeded()->create([
            'estimate_min' => 357_781_250,
            'estimate_likely' => 412_562_500,
            'estimate_max' => 467_343_750,
            'provider_cost' => 330_050_000,
            'charged' => 412_562_500,
        ]);
    });

    it('uses the {display, exact} shape for the estimate and the charge', function () {
        $json = generationJson($this->generation);

        expect($json['estimate'])->toBe([
            'min' => ['display' => '$0.3578', 'exact' => '0.357781250'],
            'likely' => ['display' => '$0.4126', 'exact' => '0.412562500'],
            'max' => ['display' => '$0.4673', 'exact' => '0.467343750'],
        ])->and($json['charged'])->toBe(['display' => '$0.4126', 'exact' => '0.412562500']);
    });

    it('matches MoneyResource', function () {
        expect(json_decode(json_encode(MoneyResource::make(467_343_750)), true))
            ->toBe(['display' => '$0.4673', 'exact' => '0.467343750']);
    });

    it('has no charge until the generation succeeded', function (string $status) {
        $generation = Generation::factory()->for($this->user)->{$status}()->create();

        expect(generationJson($generation))->toMatchArray(['charged' => null, 'estimate_position' => null]);
    })->with(['waiting', 'submitting', 'queued', 'processing', 'failed']);

    it('never sends a number for an amount', function () {
        $json = generationJson($this->generation);

        foreach ([...array_values($json['estimate']), $json['charged']] as $money) {
            expect($money['display'])->toBeString()->and($money['exact'])->toBeString();
        }
    });
});

describe('estimate_position', function () {
    it('says where the actual price landed between min (0) and max (1)', function (int $charged, float $position) {
        $generation = Generation::factory()->for($this->user)->succeeded()->create([
            'estimate_min' => 100_000_000,
            'estimate_likely' => 300_000_000,
            'estimate_max' => 500_000_000,
            'charged' => $charged,
        ]);

        expect(generationJson($generation)['estimate_position'])->toEqualWithDelta($position, 1e-9);
    })->with([
        'at the minimum' => [100_000_000, 0.0],
        'a quarter of the way' => [200_000_000, 0.25],
        'halfway' => [300_000_000, 0.5],
        'at the maximum' => [500_000_000, 1.0],
    ]);

    it('is 0.5 for the worked example landing on likely', function () {
        $generation = Generation::factory()->for($this->user)->succeeded()->create([
            'estimate_min' => 357_781_250,
            'estimate_likely' => 412_562_500,
            'estimate_max' => 467_343_750,
            'charged' => 412_562_500,
        ]);

        expect(generationJson($generation)['estimate_position'])->toEqualWithDelta(0.5, 1e-9);
    });
});

describe('image', function () {
    it('points at the stored-image route when a copy is stored, even after the Pixelforge URL expired', function () {
        $generation = Generation::factory()->for($this->user)->succeeded()->create([
            'image_path' => 'copy.png',
            'image_url' => 'https://cdn.pixelforge.test/images/gen_1.png?sig=abc',
            'image_url_expires_at' => now()->subHour(),
        ]);

        expect(generationJson($generation))->toMatchArray([
            'image_src' => route('generations.image', $generation, absolute: false),
            'image_expired' => false,
        ]);
    });

    it('has no image and says it expired when there is no copy and the Pixelforge URL expired', function () {
        $generation = Generation::factory()->for($this->user)->succeeded()->create([
            'image_path' => null,
            'image_url' => 'https://cdn.pixelforge.test/images/gen_1.png?sig=abc',
            'image_url_expires_at' => now()->subSecond(),
        ]);

        expect(generationJson($generation))->toMatchArray([
            'image_src' => null,
            'image_expired' => true,
        ]);
    });
});
