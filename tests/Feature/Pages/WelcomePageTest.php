<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the welcome credit and markup from config to guests', function () {
    config(['pixelcat.welcome_credit' => 2_000_000_000, 'pixelcat.markup_percent' => 25]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('welcomeCredit', '$2.00')
            ->where('markupPercent', 25));
});

it('is also reachable when signed in', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});
