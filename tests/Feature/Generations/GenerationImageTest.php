<?php

use App\Events\GenerationSucceeded;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Stored copies of generated images (FR-012, US3 scenario 2) and the owner-only image route.
 */
const IMAGE_PNG = "\x89PNG\r\n\x1a\nnot-really-a-png";

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    Storage::fake('images');
    $this->user = User::factory()->create();
    $this->generation = Generation::factory()->for($this->user)->succeeded()->create([
        'image_url' => 'https://cdn.pixelforge.test/images/gen_1.png?sig=abc',
        'image_url_expires_at' => now()->addDay(),
    ]);
});

describe('StoreGenerationImage', function () {
    it('is dispatched when a generation succeeds', function () {
        Queue::fake([StoreGenerationImage::class]);

        GenerationSucceeded::dispatch($this->generation);

        Queue::assertPushed(StoreGenerationImage::class, fn (StoreGenerationImage $job) => $job->generationId === $this->generation->id);
    });

    it('stores the image once on the images disk', function () {
        Http::fake(['cdn.pixelforge.test/*' => Http::response(IMAGE_PNG, 200, ['Content-Type' => 'image/png'])]);

        StoreGenerationImage::dispatchSync($this->generation->id);

        $path = $this->generation->fresh()->image_path;
        expect($path)->toBe($this->generation->id.'.png');
        Storage::disk('images')->assertExists($path);
        expect(Storage::disk('images')->get($path))->toBe(IMAGE_PNG);
        Http::assertSentCount(1);
    });

    it('skips a generation whose image is already stored', function () {
        Http::fake();
        $this->generation->update(['image_path' => 'already.png']);

        StoreGenerationImage::dispatchSync($this->generation->id);

        Http::assertNothingSent();
        expect($this->generation->fresh()->image_path)->toBe('already.png');
    });

    it('is unique per generation and tries 3 times', function () {
        $job = new StoreGenerationImage($this->generation->id);

        expect($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe($this->generation->id)
            ->and($job->tries)->toBe(3);
    });

    it('retries later when the download fails', function (int $status) {
        Http::fake(['cdn.pixelforge.test/*' => Http::response('nope', $status)]);
        $job = (new StoreGenerationImage($this->generation->id))->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(30);
        expect($this->generation->fresh()->image_path)->toBeNull();
        Storage::disk('images')->assertDirectoryEmpty('/');
    })->with([403, 500]);

    it('gives up after the third attempt and keeps the Pixelforge URL', function () {
        Log::spy();
        Http::fake(['cdn.pixelforge.test/*' => Http::response('nope', 500)]);
        $job = (new StoreGenerationImage($this->generation->id))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        $job->handle();

        $job->assertNotReleased();
        Log::shouldHaveReceived('error')->once();
        expect($this->generation->fresh()->image_path)->toBeNull()
            ->and($this->generation->fresh()->image_url)->not->toBeNull();
    });
});

describe('GET /generations/{generation}/image', function () {
    it('redirects the owner to a 5-minute signed URL of the stored copy', function () {
        Storage::disk('images')->put('copy.png', IMAGE_PNG);
        Storage::disk('images')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiresAt) => "https://bucket.test/{$path}?expires={$expiresAt->getTimestamp()}",
        );
        $this->generation->update(['image_path' => 'copy.png']);

        $this->actingAs($this->user)
            ->get("/generations/{$this->generation->id}/image")
            ->assertRedirect('https://bucket.test/copy.png?expires='.now()->addMinutes(5)->getTimestamp());
    });

    it('streams the stored copy from a disk without signed URLs', function () {
        Storage::disk('images')->put('copy.png', IMAGE_PNG);
        $this->generation->update(['image_path' => 'copy.png', 'image_url_expires_at' => now()->subHour()]);

        $response = $this->actingAs($this->user)->get("/generations/{$this->generation->id}/image");

        $response->assertOk();
        expect($response->streamedContent())->toBe(IMAGE_PNG);
    });

    it('falls back to the Pixelforge URL while it is valid', function () {
        $this->actingAs($this->user)
            ->get("/generations/{$this->generation->id}/image")
            ->assertRedirect('https://cdn.pixelforge.test/images/gen_1.png?sig=abc');
    });

    it('is 404 when there is no copy and the Pixelforge URL has expired', function () {
        $this->generation->update(['image_url_expires_at' => now()->subSecond()]);

        $this->actingAs($this->user)
            ->get("/generations/{$this->generation->id}/image")
            ->assertNotFound();
    });

    it('is 404 for a generation without an image', function () {
        $generation = Generation::factory()->for($this->user)->processing()->create();

        $this->actingAs($this->user)->get("/generations/{$generation->id}/image")->assertNotFound();
    });

    it("is 404 for another user's generation", function () {
        $this->actingAs(User::factory()->create())
            ->get("/generations/{$this->generation->id}/image")
            ->assertNotFound();
    });

    it('is 404 for an id that is not a uuid', function () {
        $this->actingAs($this->user)->get('/generations/not-a-uuid/image')->assertNotFound();
    });

    it('requires sign-in', function () {
        $this->get("/generations/{$this->generation->id}/image")->assertRedirect('/login');
    });
});
