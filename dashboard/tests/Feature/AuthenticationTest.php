<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_every_management_page(): void
    {
        $website = Website::create(['name' => 'Site', 'url' => 'https://site.test', 'api_key' => 'secret']);

        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('websites.index'))->assertRedirect(route('login'));
        $this->get(route('websites.show', $website))->assertRedirect(route('login'));
        $this->getJson(route('websites.poll-status', $website))->assertUnauthorized();
    }

    public function test_guests_cannot_trigger_destructive_actions(): void
    {
        Http::fake();
        $website = Website::create(['name' => 'Site', 'url' => 'https://site.test', 'api_key' => 'secret']);

        $this->post(route('websites.trigger-restore', $website), ['backup_id' => 'spsync-backup-1'])->assertRedirect(route('login'));
        $this->post(route('websites.update-url', $website), ['old_url' => 'https://a.test', 'new_url' => 'https://b.test'])->assertRedirect(route('login'));
        $this->delete(route('websites.destroy', $website))->assertRedirect(route('login'));

        Http::assertNothingSent();
        $this->assertModelExists($website);
    }

    public function test_operator_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->get('/')->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_operator_accounts_are_created_from_the_console(): void
    {
        $this->artisan('dashboard:user', ['email' => 'ops@example.test', '--name' => 'Ops', '--password' => 'a-long-enough-password'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'ops@example.test', 'name' => 'Ops']);

        $this->artisan('dashboard:user', ['email' => 'weak@example.test', '--name' => 'Weak', '--password' => 'short'])
            ->assertFailed();
    }
}
