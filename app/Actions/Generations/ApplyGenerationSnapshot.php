<?php

namespace App\Actions\Generations;

use App\Actions\Pricing\CalculateCost;
use App\Billing\Ledger;
use App\Billing\Money;
use App\Enums\DeliveryOutcome;
use App\Enums\GenerationStatus;
use App\Events\GenerationAccepted;
use App\Events\GenerationFinished;
use App\Events\GenerationSucceeded;
use App\Models\Generation;
use App\Pixelforge\GenerationSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The only place that changes a generation's status or money once it has been sent to
 * Pixelforge. Webhooks, polling, the submit job and replays all come through here.
 *
 * Rules (spec "State Machine"): a snapshot only moves a generation to a higher rank;
 * anything else just fills in missing data. Final states never change. Money moves in
 * the same transaction as the status change, under a row lock, and the ledger's unique
 * index makes a second charge impossible even if two requests race.
 */
final class ApplyGenerationSnapshot
{
    public function __construct(
        private CalculateCost $calculateCost,
        private Ledger $ledger,
    ) {}

    /**
     * @param  'submit'|'webhook'|'poll'|'replay'  $source
     */
    public function __invoke(Generation $generation, GenerationSnapshot $snapshot, string $source): DeliveryOutcome
    {
        $wasAccepted = false;

        $outcome = DB::transaction(function () use ($generation, $snapshot, $source, &$wasAccepted) {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            $wasAccepted = $locked->status->rank() >= GenerationStatus::Queued->rank();

            $this->fillMissingData($locked, $snapshot);

            $outcome = $snapshot->status->rank() > $locked->status->rank()
                ? $this->moveForward($locked, $snapshot, $source)
                : $this->handleStale($locked, $snapshot, $source);

            $locked->last_synced_at = now();
            $locked->save();

            $generation->setRawAttributes($locked->getAttributes(), true);

            return $outcome;
        });

        // Pixelforge has it now and no result yet: the sync slice schedules a follow-up.
        if ($outcome === DeliveryOutcome::Applied && ! $wasAccepted && ! $generation->status->isFinal()) {
            GenerationAccepted::dispatch($generation);
        }

        if ($outcome === DeliveryOutcome::Applied && $generation->status->isFinal()) {
            if ($generation->status === GenerationStatus::Succeeded) {
                GenerationSucceeded::dispatch($generation);
            }

            GenerationFinished::dispatch($generation);
        }

        return $outcome;
    }

    private function moveForward(Generation $generation, GenerationSnapshot $snapshot, string $source): DeliveryOutcome
    {
        $generation->status = $snapshot->status;

        match ($snapshot->status) {
            GenerationStatus::Queued => $generation->recordEvent('accepted', 'Accepted by Pixelforge', ['source' => $source]),
            GenerationStatus::Processing => $generation->recordEvent('processing', 'Generating', ['source' => $source]),
            GenerationStatus::Succeeded => $this->succeed($generation, $snapshot, $source),
            GenerationStatus::Failed => $this->fail($generation, $snapshot, $source),
            default => null,
        };

        return DeliveryOutcome::Applied;
    }

    private function succeed(Generation $generation, GenerationSnapshot $snapshot, string $source): void
    {
        $this->recordCost($generation, $snapshot);
        $charged = $this->calculateCost->userPrice((int) $generation->provider_cost);
        $generation->charged = $charged;
        $generation->completed_at = $snapshot->completedAt;
        $generation->save();

        $held = $this->ledger->held($generation);
        $this->ledger->settle($generation);

        if ($held !== null && $charged > $held) {
            // Possible only if prices rose while the generation waited for a slot.
            Log::warning('Charged more than the held estimate.', [
                'generation_id' => $generation->id,
                'held' => $held,
                'charged' => $charged,
            ]);
        }

        $generation->recordEvent('succeeded', 'Done. Charged '.Money::display($charged), ['source' => $source]);
    }

    private function fail(Generation $generation, GenerationSnapshot $snapshot, string $source): void
    {
        $generation->error_code = $snapshot->errorCode ?? 'generation_failed';
        $generation->error_message = $snapshot->errorMessage;
        $generation->completed_at = $snapshot->completedAt;
        $generation->save();

        $this->ledger->release($generation);

        $generation->recordEvent('failed', "Failed. You weren't charged.", [
            'source' => $source,
            'error_code' => $generation->error_code,
        ]);
    }

    private function handleStale(Generation $generation, GenerationSnapshot $snapshot, string $source): DeliveryOutcome
    {
        // We failed it locally, but Pixelforge says it succeeded: never charge the user,
        // but record what Pixelforge bills so reconciliation still matches.
        if ($generation->status === GenerationStatus::Failed
            && $snapshot->status === GenerationStatus::Succeeded
            && ! $generation->is_anomaly) {
            $this->recordCost($generation, $snapshot);
            $generation->completed_at = $snapshot->completedAt;
            $generation->is_anomaly = true;
            $generation->recordEvent('anomaly', 'Pixelforge reported success after this generation had failed. You weren\'t charged.', ['source' => $source]);

            Log::critical('Pixelforge succeeded a generation we had marked failed.', [
                'generation_id' => $generation->id,
                'pixelforge_id' => $snapshot->id,
                'provider_cost' => $generation->provider_cost,
            ]);
        }

        return DeliveryOutcome::OutOfOrder;
    }

    private function recordCost(Generation $generation, GenerationSnapshot $snapshot): void
    {
        // Pixelforge's per-generation rates are the source of truth (fixed at creation).
        $rates = $snapshot->rates ?? $generation->rates;

        if ($snapshot->usage === null || $rates === null) {
            throw new RuntimeException("Pixelforge snapshot for [{$snapshot->id}] has no usage or rates.");
        }

        $generation->input_text_tokens = $snapshot->usage['input_text_tokens'];
        $generation->input_image_tokens = $snapshot->usage['input_image_tokens'];
        $generation->output_tokens = $snapshot->usage['output_tokens'];
        $generation->reasoning_tokens = $snapshot->usage['reasoning_tokens'];
        $generation->provider_cost = $this->calculateCost->providerCost($snapshot->usage, $rates);
    }

    /**
     * Data that may arrive in any order is filled in whenever it is still missing.
     */
    private function fillMissingData(Generation $generation, GenerationSnapshot $snapshot): void
    {
        $generation->pixelforge_id ??= $snapshot->id;
        $generation->rates = $snapshot->rates ?? $generation->rates;
        $generation->provider_created_at ??= $snapshot->createdAt;
        $generation->started_at ??= $snapshot->startedAt;

        if ($snapshot->imageUrl !== null) {
            $generation->image_url = $snapshot->imageUrl;
            $generation->image_url_expires_at = $snapshot->imageUrlExpiresAt;
        }
    }
}
