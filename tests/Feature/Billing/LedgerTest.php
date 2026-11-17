<?php

use App\Billing\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const LEDGER_HELD = 500_000_000;
const LEDGER_CHARGED = 467_343_750;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->user = User::factory()->create();
    // A new user may already have a welcome grant (FR-018); compare against this.
    $this->startingBalance = $this->ledger->balance($this->user);
});

/** A raw insert that bypasses Ledger, to prove the database itself enforces a rule. */
function insertLedgerRow(int $userId, ?string $generationId, string $type, int $amount): void
{
    // A savepoint, so a rejected insert doesn't abort the test's transaction on Postgres.
    DB::transaction(fn () => DB::table('ledger_entries')->insert([
        'user_id' => $userId,
        'generation_id' => $generationId,
        'type' => $type,
        'amount' => $amount,
        'description' => 'Inserted by a test',
        'created_at' => now(),
    ]));
}

/** @return list<array{type: string, amount: int}> */
function generationEntries(Generation $generation): array
{
    return LedgerEntry::query()
        ->where('generation_id', $generation->id)
        ->orderBy('id')
        ->get()
        ->map(fn (LedgerEntry $entry) => ['type' => $entry->type->value, 'amount' => (int) $entry->amount])
        ->all();
}

describe('balance', function () {
    it('is the sum of the user\'s entries', function () {
        $this->ledger->grant($this->user, 2_000_000_000, 'Welcome credit');
        $this->ledger->purchase($this->user, 10_000_000_000);

        $sum = (int) LedgerEntry::query()->where('user_id', $this->user->id)->sum('amount');

        expect($this->ledger->balance($this->user))->toBe($sum)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance + 12_000_000_000);
    });

    it('ignores other users\' entries', function () {
        $this->ledger->grant(User::factory()->create(), 7_000_000_000, 'Someone else');

        expect($this->ledger->balance($this->user))->toBe($this->startingBalance);
    });

    it('is an exact integer even past float precision', function () {
        $this->ledger->purchase($this->user, 9_007_199_254_740_993); // 2^53 + 1

        expect($this->ledger->balance($this->user))->toBe($this->startingBalance + 9_007_199_254_740_993);
    });
});

describe('grant and purchase', function () {
    it('grants credit with a description', function () {
        $entry = $this->ledger->grant($this->user, 2_000_000_000, 'Welcome credit');

        expect($entry)->toBeInstanceOf(LedgerEntry::class)
            ->and($entry->type)->toBe(LedgerEntryType::Grant)
            ->and((int) $entry->amount)->toBe(2_000_000_000)
            ->and($entry->description)->toBe('Welcome credit')
            ->and($entry->user_id)->toBe($this->user->id)
            ->and($entry->generation_id)->toBeNull()
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance + 2_000_000_000);
    });

    it('records a purchase', function () {
        $entry = $this->ledger->purchase($this->user, 10_000_000_000);

        expect($entry->type)->toBe(LedgerEntryType::Purchase)
            ->and((int) $entry->amount)->toBe(10_000_000_000)
            ->and($entry->user_id)->toBe($this->user->id)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance + 10_000_000_000);
    });

    it('allows any number of grants and purchases per user', function () {
        $this->ledger->purchase($this->user, 5_000_000_000);
        $this->ledger->purchase($this->user, 5_000_000_000);
        insertLedgerRow($this->user->id, null, 'grant', 1);
        insertLedgerRow($this->user->id, null, 'grant', 1);

        expect($this->ledger->balance($this->user))->toBe($this->startingBalance + 10_000_000_002);
    });
});

describe('hold, settle and release', function () {
    beforeEach(function () {
        $this->generation = Generation::factory()->for($this->user)->waiting()->create(['estimate_max' => LEDGER_HELD]);
    });

    it('holds the amount as a negative entry on the generation', function () {
        $entry = $this->ledger->hold($this->generation, LEDGER_HELD);

        expect($entry->type)->toBe(LedgerEntryType::Hold)
            ->and((int) $entry->amount)->toBe(-LEDGER_HELD)
            ->and($entry->generation_id)->toBe($this->generation->id)
            ->and($entry->user_id)->toBe($this->user->id)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance - LEDGER_HELD);
    });

    it('settles a success with a charge and a release of the hold', function () {
        $this->ledger->hold($this->generation, LEDGER_HELD);
        $this->generation->forceFill(['charged' => LEDGER_CHARGED])->save();

        $this->ledger->settle($this->generation);

        // Charge and release may be written in either order.
        expect(generationEntries($this->generation))->toEqualCanonicalizing([
            ['type' => 'hold', 'amount' => -LEDGER_HELD],
            ['type' => 'charge', 'amount' => -LEDGER_CHARGED],
            ['type' => 'hold_release', 'amount' => LEDGER_HELD],
        ]);

        expect($this->ledger->balance($this->user))->toBe($this->startingBalance - LEDGER_CHARGED);
    });

    it('settles only once', function () {
        $this->ledger->hold($this->generation, LEDGER_HELD);
        $this->generation->forceFill(['charged' => LEDGER_CHARGED])->save();

        $this->ledger->settle($this->generation);
        $this->ledger->settle($this->generation->fresh());

        expect(generationEntries($this->generation))->toHaveCount(3)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance - LEDGER_CHARGED);
    });

    it('releases the hold in full on failure', function () {
        $this->ledger->hold($this->generation, LEDGER_HELD);

        $this->ledger->release($this->generation);

        expect(generationEntries($this->generation))->toBe([
            ['type' => 'hold', 'amount' => -LEDGER_HELD],
            ['type' => 'hold_release', 'amount' => LEDGER_HELD],
        ])->and($this->ledger->balance($this->user))->toBe($this->startingBalance);
    });

    it('releases only once', function () {
        $this->ledger->hold($this->generation, LEDGER_HELD);

        $this->ledger->release($this->generation);
        $this->ledger->release($this->generation->fresh());

        expect(generationEntries($this->generation))->toHaveCount(2)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance);
    });

    it('does nothing when releasing a generation without a hold', function () {
        $this->ledger->release($this->generation);

        expect(generationEntries($this->generation))->toBe([])
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance);
    });

    it('does not release again after settling', function () {
        $this->ledger->hold($this->generation, LEDGER_HELD);
        $this->generation->forceFill(['charged' => LEDGER_CHARGED])->save();
        $this->ledger->settle($this->generation);

        $this->ledger->release($this->generation->fresh());

        expect(generationEntries($this->generation))->toHaveCount(3)
            ->and($this->ledger->balance($this->user))->toBe($this->startingBalance - LEDGER_CHARGED);
    });

    it('locks the user row inside a transaction', function () {
        DB::transaction(function () {
            $this->ledger->lockUser($this->user);
            $this->ledger->hold($this->generation, LEDGER_HELD);
        });

        expect($this->ledger->balance($this->user))->toBe($this->startingBalance - LEDGER_HELD);
    });
});

describe('database guarantees', function () {
    beforeEach(function () {
        $this->generation = Generation::factory()->for($this->user)->waiting()->create(['estimate_max' => LEDGER_HELD]);
    });

    it('rejects a second entry of the same type for a generation', function (string $type, int $amount) {
        insertLedgerRow($this->user->id, $this->generation->id, $type, $amount);

        expect(fn () => insertLedgerRow($this->user->id, $this->generation->id, $type, $amount))
            ->toThrow(QueryException::class);

        expect(LedgerEntry::query()->where('generation_id', $this->generation->id)->count())->toBe(1);
    })->with([
        'charge' => ['charge', -LEDGER_CHARGED],
        'hold' => ['hold', -LEDGER_HELD],
        'hold_release' => ['hold_release', LEDGER_HELD],
    ]);

    it('rejects a second hold through the ledger', function () {
        $this->ledger->hold($this->generation, LEDGER_HELD);

        expect(fn () => DB::transaction(fn () => $this->ledger->hold($this->generation, LEDGER_HELD)))
            ->toThrow(QueryException::class);

        expect($this->ledger->balance($this->user))->toBe($this->startingBalance - LEDGER_HELD);
    });

    it('rejects an amount with the wrong sign for its type', function (string $type, int $amount) {
        expect(fn () => insertLedgerRow($this->user->id, $this->generation->id, $type, $amount))
            ->toThrow(QueryException::class);

        expect(LedgerEntry::query()->where('user_id', $this->user->id)->sum('amount'))->toEqual($this->startingBalance);
    })->with([
        'positive hold' => ['hold', LEDGER_HELD],
        'positive charge' => ['charge', LEDGER_CHARGED],
        'negative hold_release' => ['hold_release', -LEDGER_HELD],
        'negative grant' => ['grant', -1],
        'negative purchase' => ['purchase', -1],
    ]);

    it('accepts each type with its correct sign', function () {
        insertLedgerRow($this->user->id, $this->generation->id, 'hold', -LEDGER_HELD);
        insertLedgerRow($this->user->id, $this->generation->id, 'charge', -LEDGER_CHARGED);
        insertLedgerRow($this->user->id, $this->generation->id, 'hold_release', LEDGER_HELD);
        insertLedgerRow($this->user->id, null, 'grant', 1);
        insertLedgerRow($this->user->id, null, 'purchase', 1);

        expect($this->ledger->balance($this->user))->toBe($this->startingBalance - LEDGER_CHARGED + 2);
    });
});
