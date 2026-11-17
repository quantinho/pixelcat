<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies a succeeded generation's image to the "images" disk, because Pixelforge's URL
 * expires 24 hours after completion (spec FR-012).
 *
 * The copy is best effort: until it exists the Pixelforge URL is shown, so any failure is
 * retried a few times and then logged, never thrown at whoever dispatched it.
 */
class StoreGenerationImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /** Seconds before the second and third attempts. */
    private const BACKOFF = [30, 120];

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        $path = $generation->id.'.png';

        try {
            $image = Http::timeout(30)->get($generation->image_url)->throw()->body();
            Storage::disk('images')->put($path, $image);
        } catch (Throwable $e) {
            $this->retryLater($e);

            return;
        }

        // Only this column: status and money belong to ApplyGenerationSnapshot.
        Generation::query()->whereKey($generation->id)->update(['image_path' => $path]);
    }

    private function retryLater(Throwable $e): void
    {
        $context = ['generation_id' => $this->generationId, 'attempt' => $this->attempts(), 'error' => $e->getMessage()];

        if ($this->attempts() >= $this->tries) {
            Log::error('Gave up copying a generated image; the Pixelforge URL is used until it expires.', $context);

            return;
        }

        Log::warning('Could not copy a generated image; retrying.', $context);
        $this->release(self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);
    }
}
