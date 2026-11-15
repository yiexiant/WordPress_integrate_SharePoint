<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Services\WordPressApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wordpress.allow_private_hosts' => false, 'services.wordpress.verify_tls' => true]);
        $this->actingAs(User::factory()->create());
    }

    public function test_api_key_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $website = Website::create(['name' => 'Site', 'url' => 'https://93.184.216.34', 'api_key' => 'plain-secret']);

        $raw = DB::table('websites')->where('id', $website->id)->value('api_key');
        $this->assertNotSame('plain-secret', $raw);
        $this->assertSame('plain-secret', $website->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $website->toArray());
    }

    public function test_edit_form_never_renders_the_stored_key(): void
    {
        $website = Website::create(['name' => 'Site', 'url' => 'https://93.184.216.34', 'api_key' => 'plain-secret']);

        $this->get(route('websites.edit', $website))->assertOk()->assertDontSee('plain-secret');
    }

    public function test_blank_key_on_update_keeps_the_stored_key(): void
    {
        $website = Website::create(['name' => 'Site', 'url' => 'https://93.184.216.34', 'api_key' => 'plain-secret']);

        $this->put(route('websites.update', $website), ['name' => 'Renamed', 'url' => 'https://93.184.216.34', 'api_key' => ''])
            ->assertRedirect(route('websites.show', $website));

        $this->assertSame('plain-secret', $website->fresh()->api_key);
        $this->assertSame('Renamed', $website->fresh()->name);
    }

    public function test_private_and_non_http_targets_are_rejected(): void
    {
        Http::fake();

        foreach (['http://127.0.0.1', 'http://10.0.0.5', 'http://169.254.169.254', 'http://[::1]', 'ftp://93.184.216.34', 'https://user:pass@93.184.216.34'] as $url) {
            $this->post(route('websites.store'), ['name' => 'Bad', 'url' => $url, 'api_key' => 'k'])
                ->assertSessionHasErrors('url');
        }

        $this->assertDatabaseCount('websites', 0);
        Http::assertNothingSent();
    }

    public function test_client_refuses_a_site_that_now_points_to_a_private_address(): void
    {
        Http::fake();
        // e.g. registered while allowed, or DNS changed afterwards.
        $website = Website::create(['name' => 'Site', 'url' => 'http://192.168.1.10', 'api_key' => 'k']);

        $result = app(WordPressApiClient::class)->triggerBackup($website);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_requests_send_the_key_without_following_redirects(): void
    {
        Http::fake(['*' => Http::response(['status' => 'healthy'])]);
        $website = Website::create(['name' => 'Site', 'url' => 'https://93.184.216.34', 'api_key' => 'plain-secret']);

        app(WordPressApiClient::class)->checkHealth($website);

        Http::assertSent(fn ($request) => $request->hasHeader('X-SPSYNC-KEY', 'plain-secret')
            && $request->url() === 'https://93.184.216.34/?rest_route=/spsync/v1/health');
    }
}
