<?php

namespace App\Actions\Generations;

use App\Actions\Pricing\EstimatePrice;
use App\Billing\Ledger;
use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\DispatchWaiting;
use App\Jobs\ExpireWaitingGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use App\Pixelforge\PixelforgeUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a submitted form into a `waiting` generation with a hold of its estimate's maximum
 * (FR-004, FR-005, FR-007, FR-017). Nothing is sent to Pixelforge here: the slot dispatcher
 * does that once a slot is free.
 *
 * Checks and the hold run in one transaction holding the user's row lock, so two submissions
 * at the same moment can neither both pass the balance or daily cap check nor create the
 * same id twice. Refusals are ValidationExceptions keyed by the contract's error code
 * (`id_reused`, `prices_changed`, `daily_cap`, `insufficient_balance`, and
 * `pixelforge_unavailable` when current prices can't be fetched).
 */
final class CreateGeneration
{
    public const PIXELFORGE_UNREACHABLE = 'Pixelforge is unreachable right now. Try again in a moment.';

    public function __construct(
        private PixelforgeClient $pixelforge,
        private EstimatePrice $estimatePrice,
        private Ledger $ledger,
    ) {}

    /**
     * Returns the new generation, or the existing one when the same id is resubmitted with
     * the same parameters (a double click or a browser resend).
     *
     * @param  list<string>  $referenceImages
     *
     * @throws ValidationException
     */
    public function __invoke(
        User $user,
        string $id,
        string $prompt,
        Size $size,
        Quality $quality,
        array $referenceImages,
        string $pricingVersion,
    ): Generation {
        try {
            $pricing = $this->pixelforge->pricing();
        } catch (PixelforgeUnavailable|PixelforgeRejected) {
            throw ValidationException::withMessages([
                'pixelforge_unavailable' => self::PIXELFORGE_UNREACHABLE,
            ])->status(503);
        }

        $estimate = ($this->estimatePrice)($prompt, $size, $quality, count($referenceImages), $pricing);
        $requestHash = self::requestHash($prompt, $size, $quality, $referenceImages);

        try {
            $generation = DB::transaction(function () use ($user, $id, $prompt, $size, $quality, $referenceImages, $pricingVersion, $pricing, $estimate, $requestHash) {
                $this->ledger->lockUser($user);

                $existing = Generation::query()->find($id);
                if ($existing !== null) {
                    if ((int) $existing->user_id !== $user->id || $existing->request_hash !== $requestHash) {
                        throw self::idReused();
                    }

                    return $existing;
                }

                if ($pricingVersion !== $estimate->pricingVersion) {
                    throw ValidationException::withMessages([
                        'prices_changed' => 'Pixelforge changed its prices. Check the new estimate and submit again.',
                    ])->status(409);
                }

                $cap = (int) config('pixelcat.daily_cap_per_user');
                if ($this->usedToday($user) >= $cap) {
                    throw ValidationException::withMessages([
                        'daily_cap' => "You've reached today's limit of {$cap} generations. It resets at midnight UTC.",
                    ])->status(429);
                }

                $missing = $estimate->max - $this->ledger->balance($user);
                if ($missing > 0) {
                    throw ValidationException::withMessages([
                        'insufficient_balance' => 'You need '.Money::display($missing).' more credit for this generation. Buy credits to continue.',
                    ]);
                }

                $generation = $user->generations()->create([
                    'id' => $id,
                    'status' => GenerationStatus::Waiting,
                    'prompt' => $prompt,
                    'size' => $size,
                    'quality' => $quality,
                    'reference_images' => $referenceImages,
                    'request_hash' => $requestHash,
                    'estimate_min' => $estimate->min,
                    'estimate_likely' => $estimate->likely,
                    'estimate_max' => $estimate->max,
                    'estimate_rates' => $pricing->rates,
                    'available_at' => now(),
                ]);

                $this->ledger->hold($generation, $estimate->max);
                $generation->recordEvent('submitted', 'Submitted. Holding up to '.Money::display($estimate->max).' until it finishes.');

                return $generation;
            });
        } catch (UniqueConstraintViolationException) {
            // Another user's submission took this id at the same moment.
            throw self::idReused();
        }

        if ($generation->wasRecentlyCreated) {
            DispatchWaiting::dispatch();
            ExpireWaitingGeneration::dispatchFor($generation);
        }

        return $generation;
    }

    /**
     * Generations the user started today (UTC), failed ones included (FR-007).
     */
    public function usedToday(User $user): int
    {
        return $user->generations()->where('created_at', '>=', CarbonImmutable::now('UTC')->startOfDay())->count();
    }

    public static function dailyCapResetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDay()->startOfDay();
    }

    /**
     * Identifies the request parameters, so a resubmitted id can be told apart from a reused one.
     *
     * @param  list<string>  $referenceImages
     */
    public static function requestHash(string $prompt, Size $size, Quality $quality, array $referenceImages): string
    {
        return hash('sha256', (string) json_encode([$prompt, $size->value, $quality->value, $referenceImages]));
    }

    private static function idReused(): ValidationException
    {
        return ValidationException::withMessages([
            'id_reused' => 'This form was already submitted with other settings. Submit again to start a new generation.',
        ])->status(409);
    }
}
