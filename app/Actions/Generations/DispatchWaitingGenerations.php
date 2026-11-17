<?php

namespace App\Actions\Generations;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Events\GenerationFinished;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hands out Pixelforge's concurrency slots fairly (FR-006, research R7): oldest first across
 * all users, at most `max_active` generations active for the account and `max_active_per_user`
 * per user, and only once a generation's `available_at` has passed (concurrency backoff).
 * Picked generations move to `submitting` and get one submit job each.
 *
 * Runs are serialized by a transaction-scoped Postgres advisory lock, so two dispatchers can't
 * both see the same free slot. The local count only avoids needless requests; Pixelforge's
 * concurrency 429 stays the source of truth.
 */
final class DispatchWaitingGenerations
{
    private const LOCK_KEY = 7_201_100_001;

    public function __construct(private Ledger $ledger) {}

    /**
     * @return array{dispatched: int, expired: int}
     */
    public function __invoke(): array
    {
        $expired = $this->expireOverdue();

        $picked = DB::transaction(function () {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            return $this->pickAndMarkSubmitting();
        });

        foreach ($picked as $generation) {
            SubmitGenerationToPixelforge::dispatch($generation);
        }

        return ['dispatched' => $picked->count(), 'expired' => $expired];
    }

    /**
     * Fails the generation if it is still waiting after the waiting limit (FR-006).
     * Returns whether it did.
     */
    public function failIfWaitedTooLong(Generation $generation): bool
    {
        $failed = DB::transaction(function () use ($generation) {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->first();

            // A generation that may exist at Pixelforge is never failed locally; it keeps
            // its place and is submitted (and resolved) when a slot frees up.
            if ($locked === null
                || $locked->status !== GenerationStatus::Waiting
                || $locked->had_ambiguous_attempt
                || self::waitingDeadline($locked)->isFuture()) {
                return null;
            }

            $locked->status = GenerationStatus::Failed;
            $locked->error_code = 'waited_too_long';
            $locked->error_message = 'Waited longer than '.config('pixelcat.max_wait_minutes').' minutes for a free slot.';
            $locked->save();

            $this->ledger->release($locked);
            $locked->recordEvent('failed', GenerationResource::errorMessage('waited_too_long'), ['error_code' => 'waited_too_long']);

            return $locked;
        });

        if ($failed === null) {
            return false;
        }

        GenerationFinished::dispatch($failed);

        return true;
    }

    /**
     * When a waiting generation gives up: the waiting limit after it was submitted.
     */
    public static function waitingDeadline(Generation $generation): CarbonInterface
    {
        return ($generation->created_at ?? now())->copy()->addMinutes((int) config('pixelcat.max_wait_minutes'));
    }

    private function expireOverdue(): int
    {
        $overdue = Generation::query()
            ->where('status', GenerationStatus::Waiting)
            ->where('had_ambiguous_attempt', false)
            ->where('created_at', '<=', now()->subMinutes((int) config('pixelcat.max_wait_minutes')))
            ->get();

        return $overdue->filter(fn (Generation $generation) => $this->failIfWaitedTooLong($generation))->count();
    }

    /**
     * @return Collection<int, Generation>
     */
    private function pickAndMarkSubmitting(): Collection
    {
        $maxPerUser = (int) config('pixelcat.max_active_per_user');

        /** @var array<int, int> $activeByUser user id => active generations */
        $activeByUser = Generation::query()
            ->whereIn('status', GenerationStatus::active())
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as active')
            ->pluck('active', 'user_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        $free = (int) config('pixelcat.max_active') - array_sum($activeByUser);
        $picked = new Collection;

        // One query per free slot (at most 3), so a user with a long queue at their own limit
        // never blocks the users behind them.
        while ($free > 0) {
            $fullUsers = array_keys(array_filter($activeByUser, fn (int $active) => $active >= $maxPerUser));

            $next = Generation::query()
                ->where('status', GenerationStatus::Waiting)
                ->where('available_at', '<=', now())
                ->whereNotIn('user_id', $fullUsers)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($next === null) {
                break;
            }

            $next->status = GenerationStatus::Submitting;
            $next->save();

            $picked->push($next);
            $activeByUser[$next->user_id] = ($activeByUser[$next->user_id] ?? 0) + 1;
            $free--;
        }

        return $picked;
    }
}
