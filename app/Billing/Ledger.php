<?php

namespace App\Billing;

use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The append-only credit ledger. A balance is the sum of a user's entries.
 *
 * A generation's money moves in at most three entries, each unique per generation
 * (enforced by a partial unique index): a hold of the estimate's maximum when it is
 * submitted, then on success a charge of the user price plus a release of the hold,
 * or on failure only the release.
 */
final class Ledger
{
    public function balance(User $user): int
    {
        return (int) LedgerEntry::query()->where('user_id', $user->id)->sum('amount');
    }

    public function grant(User $user, int $amount, string $description): LedgerEntry
    {
        return $this->write($user->id, null, LedgerEntryType::Grant, $amount, $description);
    }

    public function purchase(User $user, int $amount): LedgerEntry
    {
        $dollars = BigDecimal::ofUnscaledValue($amount, 9)->toScale(2, RoundingMode::HalfUp);

        return $this->write($user->id, null, LedgerEntryType::Purchase, $amount, "Bought \${$dollars} of credits");
    }

    public function hold(Generation $generation, int $amount): LedgerEntry
    {
        return $this->write($generation->user_id, $generation->id, LedgerEntryType::Hold, -$amount, 'Held while generating');
    }

    /**
     * Charge the user price and release the hold. Safe to call twice.
     * Call inside the transaction that marks the generation succeeded.
     */
    public function settle(Generation $generation): void
    {
        if ($generation->charged === null) {
            throw new \LogicException("Generation [{$generation->id}] has no user price to charge.");
        }

        if (! $this->has($generation, LedgerEntryType::Charge)) {
            $this->write($generation->user_id, $generation->id, LedgerEntryType::Charge, -$generation->charged, 'Image generated');
        }

        $this->release($generation);
    }

    /**
     * Release the hold, if there is one and it isn't released yet. Safe to call twice.
     */
    public function release(Generation $generation): void
    {
        $held = $this->held($generation);

        if ($held === null || $this->has($generation, LedgerEntryType::HoldRelease)) {
            return;
        }

        $this->write($generation->user_id, $generation->id, LedgerEntryType::HoldRelease, $held, 'Hold released');
    }

    /**
     * The amount held for a generation, or null if nothing was held.
     */
    public function held(Generation $generation): ?int
    {
        $amount = LedgerEntry::query()
            ->where('generation_id', $generation->id)
            ->where('type', LedgerEntryType::Hold)
            ->value('amount');

        return $amount === null ? null : -(int) $amount;
    }

    /**
     * Serialize balance checks and holds for one user. Call inside a transaction.
     */
    public function lockUser(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }

    private function has(Generation $generation, LedgerEntryType $type): bool
    {
        return LedgerEntry::query()
            ->where('generation_id', $generation->id)
            ->where('type', $type)
            ->exists();
    }

    private function write(int $userId, ?string $generationId, LedgerEntryType $type, int $amount, string $description): LedgerEntry
    {
        return LedgerEntry::query()->create([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
        ]);
    }
}
