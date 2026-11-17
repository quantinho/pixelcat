<?php

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePixelforge;

beforeEach(function () {
    $this->pixelforge = FakePixelforge::fake();
    $this->pixelforge->store(FakePixelforge::generation('processing', ['id' => 'gen_busy1']));
    $this->client = app(PixelforgeClient::class);
});

/** Make Pixelforge answer a 503 to a create request. */
function overloadPixelforge(FakePixelforge $pixelforge, PixelforgeClient $client): void
{
    $pixelforge->createReturns('overloaded');
    $generation = Generation::factory()->for(User::factory())->submitting()->create();

    try {
        $client->createGeneration($generation);
    } catch (PixelforgeRejected $rejected) {
        expect($rejected->errorCode)->toBe('overloaded')
            ->and($rejected->httpStatus)->toBe(503)
            ->and($rejected->retryAfter)->toBe(2);

        return;
    }

    throw new RuntimeException('Expected createGeneration() to throw PixelforgeRejected for a 503.');
}

it('is not busy before Pixelforge has answered anything', function () {
    expect($this->client->isBusy())->toBeFalse()
        ->and($this->client->remainingRequests())->toBeNull();
});

it('remembers the last X-RateLimit-Remaining', function () {
    $this->pixelforge->withRateLimitRemaining(42);

    $this->client->getGeneration('gen_busy1');

    expect($this->client->remainingRequests())->toBe(42);
});

it('is busy while fewer than 10 requests remain this minute', function (int $remaining, bool $busy) {
    $this->pixelforge->withRateLimitRemaining($remaining);

    $this->client->getGeneration('gen_busy1');

    expect($this->client->isBusy())->toBe($busy);
})->with([
    'none left' => [0, true],
    '9 left' => [9, true],
    '10 left' => [10, false],
    '59 left' => [59, false],
]);

it('stops being busy when a later response has room again', function () {
    $this->pixelforge->withRateLimitRemaining(5);
    $this->client->getGeneration('gen_busy1');
    expect($this->client->isBusy())->toBeTrue();

    $this->pixelforge->withRateLimitRemaining(30);
    $this->client->getGeneration('gen_busy1');
    expect($this->client->isBusy())->toBeFalse();
});

it('is busy for 2 minutes after a 503', function () {
    overloadPixelforge($this->pixelforge, $this->client);

    expect($this->client->isBusy())->toBeTrue();

    $this->travel(119)->seconds();
    expect($this->client->isBusy())->toBeTrue();

    $this->travel(2)->seconds();
    expect($this->client->isBusy())->toBeFalse();
});

it('shares the busy state across client instances (web requests and jobs)', function () {
    overloadPixelforge($this->pixelforge, $this->client);

    app()->forgetInstance(PixelforgeClient::class);

    expect(app(PixelforgeClient::class)->isBusy())->toBeTrue();
});

it('shares pixelforge_busy with every page (FR-014)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pixelforge_busy', false));

    $this->pixelforge->withRateLimitRemaining(3);
    $this->client->getGeneration('gen_busy1');

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('pixelforge_busy', true));

    $this->travel(1)->minute();
    $this->pixelforge->withRateLimitRemaining(50);
    $this->client->getGeneration('gen_busy1');

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('pixelforge_busy', false));
});

it('shares pixelforge_busy after a 503 and clears it 2 minutes later', function () {
    $user = User::factory()->create();
    overloadPixelforge($this->pixelforge, $this->client);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('pixelforge_busy', true));

    $this->travel(121)->seconds();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('pixelforge_busy', false));
});
