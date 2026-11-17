<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Re-applies a stored webhook event (spec FR-021), e.g. after fixing a bug. It goes through
 * ApplyGenerationSnapshot like everything else, so replaying is always safe: an event that
 * was already applied changes nothing.
 */
class ReplayEvent extends Command
{
    protected $signature = 'pixelforge:replay-event {event_id : The Pixelforge event id (evt_…)}';

    protected $description = 'Re-apply a stored Pixelforge webhook event';

    public function handle(ApplyGenerationSnapshot $apply): int
    {
        $eventId = (string) $this->argument('event_id');
        $event = WebhookEvent::query()->find($eventId);

        if ($event === null) {
            $this->error("No stored event [{$eventId}].");

            return self::FAILURE;
        }

        $object = $event->payload['data']['object'] ?? null;
        if (! is_array($object)) {
            $this->error("Event [{$eventId}] carries no generation.");

            return self::FAILURE;
        }

        $snapshot = GenerationSnapshot::fromArray($object);
        $generation = $event->generation ?? $this->findGeneration($snapshot);

        if ($generation === null) {
            $this->error("No generation matches event [{$eventId}] (Pixelforge id {$snapshot->id}).");

            return self::FAILURE;
        }

        $outcome = $apply($generation, $snapshot, 'replay');

        $this->info("Event [{$eventId}] for generation {$generation->id}: {$outcome->value}; status is now {$generation->status->value}.");

        return self::SUCCESS;
    }

    private function findGeneration(GenerationSnapshot $snapshot): ?Generation
    {
        if ($snapshot->localId !== null && Str::isUuid($snapshot->localId)) {
            $generation = Generation::query()->find($snapshot->localId);

            if ($generation !== null) {
                return $generation;
            }
        }

        return Generation::query()->where('pixelforge_id', $snapshot->id)->first();
    }
}
