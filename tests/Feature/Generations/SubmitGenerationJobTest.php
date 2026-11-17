<?php

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Events\GenerationAccepted;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePixelforge;

/**
 * The submit job's happy path (FR-005, FR-008): one create request with our id as the
 * Idempotency-Key, and Pixelforge's 202 applied through ApplyGenerationSnapshot.
 */
beforeEach(function () {
    Event::fake([GenerationAccepted::class]);
    Queue::fake();
    $this->pixelforge = FakePixelforge::fake();

    $this->submitting = function (array $attributes = []): Generation {
        $generation = Generation::factory()->submitting()->create(['submit_attempts' => 0, ...$attributes]);
        app(Ledger::class)->grant($generation->user, $generation->estimate_max, 'Welcome credit');
        app(Ledger::class)->hold($generation, $generation->estimate_max);

        return $generation;
    };

    $this->run = fn (Generation $generation) => app()->call([
        (new SubmitGenerationToPixelforge($generation))->withFakeQueueInteractions(),
        'handle',
    ]);
});

it('sends exactly the documented request', function () {
    $generation = ($this->submitting)(['prompt' => 'A cat in a hat [succeed]', 'size' => '1536x1024', 'quality' => 'high']);

    ($this->run)($generation);

    Http::assertSent(function (Request $request) use ($generation) {
        return $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->header('Idempotency-Key') === [$generation->id]
            && $request->header('Authorization') === ['Bearer test-key']
            && $request->data() === [
                'prompt' => 'A cat in a hat [succeed]',
                'size' => '1536x1024',
                'quality' => 'high',
                'metadata' => ['local_id' => $generation->id],
            ];
    });
    expect($this->pixelforge->createCalls())->toBe(1);
});

it('sends reference images when there are any', function () {
    $generation = ($this->submitting)(['reference_images' => ['https://example.com/a.png', 'https://example.com/b.png']]);

    ($this->run)($generation);

    Http::assertSent(fn (Request $request) => $request->data()['reference_images'] === ['https://example.com/a.png', 'https://example.com/b.png']);
});

it('stores the accepted generation as queued with its Pixelforge id and rates', function () {
    $generation = ($this->submitting)();

    ($this->run)($generation);

    $stored = $this->pixelforge->storedFor($generation->id);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe($stored['id'])
        ->and($generation->rates)->toEqual(FakePixelforge::RATES)
        ->and($generation->submit_attempts)->toBe(1)
        ->and($generation->had_ambiguous_attempt)->toBeFalse()
        ->and($generation->last_synced_at)->not->toBeNull()
        ->and($generation->events()->pluck('type')->all())->toBe(['accepted'])
        ->and(app(Ledger::class)->held($generation))->toBe($generation->estimate_max);

    Event::assertDispatched(GenerationAccepted::class, fn (GenerationAccepted $event) => $event->generation->is($generation));
});

it('does nothing for a generation that is not submitting', function (string $state) {
    $generation = Generation::factory()->{$state}()->create();
    $before = $generation->fresh()->status;

    ($this->run)($generation);

    expect($this->pixelforge->createCalls())->toBe(0)
        ->and($generation->fresh()->status)->toBe($before);
})->with(['waiting', 'queued', 'processing', 'succeeded', 'failed']);
