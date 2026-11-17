<?php

use App\Http\Middleware\ProtectSharedDemoAccount;
use App\Models\User;
use Database\Seeders\UsersSeeder;

beforeEach(function () {
    $this->demo = User::factory()->create(['email' => UsersSeeder::DEMO_EMAIL]);
});

it('refuses changes that would lock other reviewers out of the demo account', function (string $method, string $uri, array $data) {
    $this->actingAs($this->demo)
        ->from('/settings/profile')
        ->call($method, $uri, $data)
        ->assertStatus(303)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => ProtectSharedDemoAccount::MESSAGE]);

    $demo = $this->demo->fresh();
    expect($demo)->not->toBeNull()
        ->and($demo->email)->toBe(UsersSeeder::DEMO_EMAIL)
        ->and($demo->name)->toBe($this->demo->name)
        ->and($demo->two_factor_secret)->toBeNull();
})->with([
    'change profile' => ['PATCH', '/settings/profile', ['name' => 'Mine now', 'email' => 'mine@example.com']],
    'delete account' => ['DELETE', '/settings/profile', ['password' => 'password']],
    'change password' => ['PUT', '/settings/password', ['current_password' => 'password', 'password' => 'n3w-Passw0rd!', 'password_confirmation' => 'n3w-Passw0rd!']],
    'turn on 2FA' => ['POST', '/user/two-factor-authentication', []],
]);

it('answers JSON requests with 403', function () {
    $this->actingAs($this->demo)
        ->deleteJson('/settings/profile', ['password' => 'password'])
        ->assertForbidden()
        ->assertJson(['message' => ProtectSharedDemoAccount::MESSAGE]);
});

it('lets every other account change its own settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'New name', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('New name');
});

it('still lets the demo account generate and buy credits', function () {
    $this->actingAs($this->demo)
        ->post('/credits/purchase', ['pack' => 5])
        ->assertSessionHasNoErrors();
});
