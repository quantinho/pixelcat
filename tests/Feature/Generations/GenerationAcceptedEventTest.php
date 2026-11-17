<?php

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Events\GenerationAccepted;
use App\Models\Generation;
use App\Pixelforge\GenerationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakePixelforge;

uses(RefreshDatabase::class);

/**
 * GenerationAccepted drives the event-driven sync (FR-011): it fires exactly once, when
 * Pixelforge first holds a generation that has no result yet.
 */
function applySnapshot(Generation $generation, string $status, array $extra = []): void
{
    $snapshot = GenerationSnapshot::fromArray(array_merge(
        FakePixelforge::generationFor($generation, $status),
        $extra,
    ));

    app(ApplyGenerationSnapshot::class)($generation, $snapshot, 'webhook');
}

it('fires when a submitted generation is accepted', function (string $status) {
    Event::fake([GenerationAccepted::class]);
    $generation = Generation::factory()->submitting()->create();

    applySnapshot($generation, $status);

    Event::assertDispatchedTimes(GenerationAccepted::class, 1);
})->with(['queued', 'processing']);

it('does not fire again once the generation is already with Pixelforge', function () {
    Event::fake([GenerationAccepted::class]);
    $generation = Generation::factory()->queued()->create();

    applySnapshot($generation, 'processing');

    Event::assertNotDispatched(GenerationAccepted::class);
});

it('does not fire when the first news is already a final result', function () {
    Event::fake([GenerationAccepted::class]);
    $generation = Generation::factory()->submitting()->create();

    applySnapshot($generation, 'failed', ['error' => ['code' => 'generation_failed', 'message' => 'x']]);

    Event::assertNotDispatched(GenerationAccepted::class);
});
