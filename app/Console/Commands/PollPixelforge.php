<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use App\Pixelforge\PixelforgeUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The hourly safety net behind the per-generation SyncGeneration jobs (spec FR-011): fetches
 * generations Pixelforge has but we haven't heard about for a while.
 *
 * It also re-dispatches generations stuck in `submitting`, whose submit job was lost: the job
 * resends with the same Idempotency-Key, so Pixelforge can never create a second generation.
 */
class PollPixelforge extends Command
{
    protected $signature = 'pixelforge:poll';

    protected $description = 'Resync generations whose webhooks are late or missing';

    /** No update for this long means a webhook (and its sync job) went missing. */
    private const STALE_AFTER_MINUTES = 15;

    private const MAX_PER_RUN = 10;

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $apply): int
    {
        // Every submit attempt touches updated_at; nothing for this long means the job is gone.
        // A job that is still alive keeps its uniqueness lock, so this dispatch is then a no-op.
        $stuck = Generation::query()
            ->where('status', GenerationStatus::Submitting)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get();

        foreach ($stuck as $generation) {
            SubmitGenerationToPixelforge::dispatch($generation);
        }

        if ($stuck->isNotEmpty()) {
            $this->info("Re-dispatched {$stuck->count()} generation(s) stuck in submitting.");
        }

        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES)))
            ->orderByRaw('last_synced_at asc nulls first')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $synced = 0;

        foreach ($stale as $generation) {
            $remaining = $client->remainingRequests();
            if ($remaining !== null && $remaining < SyncGeneration::MIN_REMAINING_REQUESTS) {
                $this->warn("Stopping: only {$remaining} Pixelforge requests left this minute.");
                break;
            }

            try {
                $apply($generation, $client->getGeneration((string) $generation->pixelforge_id), 'poll');
                $synced++;
            } catch (PixelforgeRejected $e) {
                $context = ['generation_id' => $generation->id, 'pixelforge_id' => $generation->pixelforge_id, 'error_code' => $e->errorCode];

                if ($e->errorCode === 'not_found') {
                    $this->recordAttempt($generation);
                }

                if (in_array($e->errorCode, ['invalid_api_key', 'not_found'], true)) {
                    Log::critical('Pixelforge refused to show a generation.', $context);
                } else {
                    Log::warning('Pixelforge refused a poll request.', $context);
                }

                // The key is wrong or we are rate limited: the rest would fail the same way.
                if ($e->errorCode !== 'not_found') {
                    break;
                }
            } catch (PixelforgeUnavailable $e) {
                $this->recordAttempt($generation);
                Log::warning('Pixelforge did not answer a poll request.', ['generation_id' => $generation->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Synced {$synced} of {$stale->count()} stale generations.");

        return self::SUCCESS;
    }

    /**
     * last_synced_at means "last time we tried to sync". A fetch that failed because of this
     * generation (unknown to Pixelforge, no answer) still counts, so it moves to the back of
     * the line and a few broken generations can't take every run's 10 places forever.
     * Failures that say nothing about the generation (bad key, rate limit) don't count.
     */
    private function recordAttempt(Generation $generation): void
    {
        Generation::query()->whereKey($generation->id)->update(['last_synced_at' => now()]);
    }
}
