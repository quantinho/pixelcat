<?php

namespace Tests\Feature\Settings;

use App\Billing\Ledger;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_is_stored_in_lowercase_so_the_user_can_still_log_in()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'Test@Example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('test@example.com', $user->refresh()->email);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'Test@Example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_must_be_unique_regardless_of_case()
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'Taken@Example.com',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('profile.edit'));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();

        // Anonymised, not deleted: the ledger and billed generations must stay (see DeleteAccount).
        $deleted = $user->fresh();
        $this->assertSame('Deleted user', $deleted->name);
        $this->assertStringEndsWith('@users.pixelcat.invalid', $deleted->email);
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'password']));
    }

    public function test_deleting_an_account_keeps_its_billing_history_but_not_its_content()
    {
        Storage::fake('images');
        $user = User::factory()->create(['is_admin' => true]);
        app(Ledger::class)->grant($user, 1_000_000_000, 'Welcome credit');
        $generation = Generation::factory()->for($user)->succeeded()->create([
            'prompt' => 'My private prompt',
            'image_path' => 'private.png',
        ]);
        Storage::disk('images')->put('private.png', 'png');
        $entries = LedgerEntry::query()->count();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        $kept = $generation->fresh();
        $this->assertSame($generation->provider_cost, $kept->provider_cost);
        $this->assertSame($generation->charged, $kept->charged);
        $this->assertSame('Deleted', $kept->prompt);
        $this->assertNull($kept->image_path);
        Storage::disk('images')->assertMissing('private.png');
        $this->assertSame($entries, LedgerEntry::query()->count());
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_deleting_an_account_leaves_unfinished_generations_as_they_were_sent()
    {
        $user = User::factory()->create();
        $running = Generation::factory()->for($user)->processing()->create(['prompt' => 'Still drawing']);

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

        // A resend under the same Idempotency-Key must carry the same prompt.
        $this->assertSame('Still drawing', $running->fresh()->prompt);
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
