<?php

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Events\GenerationAccepted;
use App\Models\Generation;
use App\Pixelforge\GenerationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakePixelforge;

uses(RefreshDatabase::class);

/**
 * GenerationAccepted drives the event-driven sync (FR-011): it fires exactly once, when
 * Pixelforge first holds a generation that has no result yet, and only after the change
 * is committed.
 */
beforeEach(function () {
    $this->applyAs = function (Generation $generation, string $status, array $extra = []): void {
        $snapshot = GenerationSnapshot::fromArray(array_merge(
            FakePixelforge::generationFor($generation, $status),
            $extra,
        ));

        app(ApplyGenerationSnapshot::class)($generation, $snapshot, 'webhook');
    };
});

it('fires when a submitted generation is accepted', function (string $status) {
    Event::fake([GenerationAccepted::class]);
    $generation = Generation::factory()->submitting()->create();

    ($this->applyAs)($generation, $status);

    Event::assertDispatchedTimes(GenerationAccepted::class, 1);
})->with(['queued', 'processing']);

it('does not fire again once the generation is already with Pixelforge', function () {
    Event::fake([GenerationAccepted::class]);
    $generation = Generation::factory()->queued()->create();

    ($this->applyAs)($generation, 'processing');

    Event::assertNotDispatched(GenerationAccepted::class);
});

it('does not fire when the first news is already a final result', function () {
    Event::fake([GenerationAccepted::class]);
    $generation = Generation::factory()->submitting()->create();

    ($this->applyAs)($generation, 'failed', ['error' => ['code' => 'generation_failed', 'message' => 'x']]);

    Event::assertNotDispatched(GenerationAccepted::class);
});

it('does not fire when the surrounding transaction rolls back', function () {
    $fired = 0;
    Event::listen(GenerationAccepted::class, function () use (&$fired) {
        $fired++;
    });
    $generation = Generation::factory()->submitting()->create();

    try {
        DB::transaction(function () use ($generation) {
            ($this->applyAs)($generation, 'queued');

            throw new RuntimeException('webhook processing failed');
        });
    } catch (RuntimeException) {
        // The webhook controller answers 5xx here and Pixelforge redelivers.
    }

    expect($fired)->toBe(0)
        ->and($generation->fresh()->status->value)->toBe('submitting');
});
