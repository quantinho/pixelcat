<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Actions\Pricing\EstimatePrice;
use App\Billing\Ledger;
use App\Billing\Money;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\MoneyResource;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use App\Pixelforge\PixelforgeUnavailable;
use App\Pixelforge\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * POST /generations/estimate: the live price range the form shows while the user types
 * (FR-003, contracts/http.md). Amounts are formatted here; Vue never does money arithmetic.
 */
class EstimateController extends Controller
{
    public function __invoke(GenerationRequest $request, PixelforgeClient $pixelforge, EstimatePrice $estimatePrice, Ledger $ledger): JsonResponse
    {
        try {
            $pricing = $pixelforge->pricing();
        } catch (PixelforgeUnavailable|PixelforgeRejected) {
            return response()->json(['message' => CreateGeneration::PIXELFORGE_UNREACHABLE], 503);
        }

        $estimate = $estimatePrice(
            $request->prompt(),
            $request->size(),
            $request->quality(),
            count($request->referenceImages()),
            $pricing,
        );

        /** @var User $user */
        $user = $request->user();
        $balance = $ledger->balance($user);

        return response()->json([
            'min' => MoneyResource::format($estimate->min),
            'likely' => MoneyResource::format($estimate->likely),
            'max' => MoneyResource::format($estimate->max),
            'breakdown' => array_map(fn (array $line) => [
                'type' => $line['type'],
                'tokens' => $this->range(number_format($line['min_tokens']), number_format($line['max_tokens'])),
                'cost' => $this->range(Money::display($line['min_cost']), Money::display($line['max_cost'])),
            ], $estimate->breakdown),
            'markup_percent' => $estimate->markupPercent,
            'pricing_version' => $estimate->pricingVersion,
            'rates_fetched_at' => $this->ratesFetchedAt($pricing),
            'affordable' => $balance >= $estimate->max,
            'balance' => MoneyResource::format($balance),
        ]);
    }

    private function range(string $min, string $max): string
    {
        return $min === $max ? $min : "{$min}–{$max}";
    }

    /**
     * The client caches pricing for 5 minutes without a timestamp, so this remembers when
     * these rates were first seen, for as long as the client keeps them.
     */
    private function ratesFetchedAt(Pricing $pricing): string
    {
        return Cache::remember('pixelforge:pricing_seen_at:'.$pricing->version(), 300, fn () => now()->utc()->toIso8601String());
    }
}
