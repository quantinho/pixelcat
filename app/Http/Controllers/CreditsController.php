<?php

namespace App\Http\Controllers;

use App\Billing\Ledger;
use App\Billing\Money;
use App\Enums\LedgerEntryType;
use App\Http\Resources\MoneyResource;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The credits page and the fake "Buy credits" action (FR-016). Purchases are not charged
 * to anyone; they only append a purchase entry to the ledger.
 */
class CreditsController extends Controller
{
    private const ENTRIES = 50;

    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('credits/Index', [
            'packs' => array_map(fn (int $dollars) => [
                'amount' => $dollars,
                'label' => self::dollars($dollars),
            ], self::packs()),
            'entries' => LedgerEntry::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(self::ENTRIES)
                ->get()
                ->map(fn (LedgerEntry $entry) => [
                    'type' => $entry->type->value,
                    'description' => $entry->description,
                    'amount' => MoneyResource::format($entry->amount),
                    'at' => $entry->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function purchase(Request $request, #[CurrentUser] User $user, Ledger $ledger): RedirectResponse
    {
        $request->validate([
            'pack' => ['required', 'integer', Rule::in(self::packs())],
        ]);

        $dollars = $request->integer('pack');
        $amount = $dollars * Money::NANOS_PER_DOLLAR;

        DB::transaction(function () use ($user, $ledger, $amount) {
            $ledger->lockUser($user);

            // Credits are free here, so a cap keeps anyone from minting a balance that would
            // let them use up the shared Pixelforge account.
            $cap = (int) config('pixelcat.daily_purchase_cap') * Money::NANOS_PER_DOLLAR;
            $addedToday = (int) LedgerEntry::query()
                ->where('user_id', $user->id)
                ->where('type', LedgerEntryType::Purchase)
                ->where('created_at', '>=', now('UTC')->startOfDay())
                ->sum('amount');

            if ($addedToday + $amount > $cap) {
                throw ValidationException::withMessages([
                    'pack' => 'You can add up to '.Money::displayCents($cap).' of demo credits a day. Try a smaller pack or come back tomorrow.',
                ]);
            }

            $ledger->purchase($user, $amount);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added '.self::dollars($dollars).' of credits']);

        return back(303);
    }

    /** @return list<int> pack sizes in whole dollars */
    private static function packs(): array
    {
        /** @var list<int> $packs */
        $packs = config('pixelcat.credit_packs');

        return $packs;
    }

    /** "$10.00" for 10; packs are whole dollars. */
    private static function dollars(int $dollars): string
    {
        return '$'.$dollars.'.00';
    }
}
