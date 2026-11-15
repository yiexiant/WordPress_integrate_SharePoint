<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Services\WordPressApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectionDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wordpress.allow_private_hosts' => true]);
    }

    private function site(): Website
    {
        return Website::create(['name' => 'Laragon', 'url' => 'https://1-backup-test.test', 'api_key' => 'k']);
    }

    public function test_plugin_is_called_through_rest_route_so_plain_permalinks_work(): void
    {
        Http::fake(['*' => Http::response([])]);
        $client = app(WordPressApiClient::class);
        $website = $this->site();

        $client->checkHealth($website);
        $client->fetchLogs($website, 20);

        Http::assertSent(fn ($request) => $request->url() === 'https://1-backup-test.test/?rest_route=/spsync/v1/health');
        Http::assertSent(fn ($request) => $request->url() === 'https://1-backup-test.test/?rest_route=/spsync/v1/logs&limit=20');
    }

    public function test_failed_check_is_shown_as_unreachable_with_an_actionable_reason(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $website = $this->site();
        $this->assertSame('Not checked yet', $website->health()->label);

        $result = app(WordPressApiClient::class)->checkHealth($website);

        $this->assertStringContainsString('plugin is installed and activated', $result['message']);
        $health = $website->fresh()->health();
        $this->assertSame('Unreachable', $health->label);
        $this->assertStringContainsString('HTTP 404', $health->reason);
    }

    public function test_rejected_key_and_redirects_are_explained(): void
    {
        $client = app(WordPressApiClient::class);

        Http::fake(['*' => Http::response(['message' => 'Unauthorized API Key.'], 401)]);
        $this->assertStringContainsString('rejected the API key', $client->checkHealth($this->site())['message']);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 301, ['Location' => 'https://www.1-backup-test.test/'])]);
        $this->assertStringContainsString('https://www.1-backup-test.test/', $client->checkHealth($this->site())['message']);
    }

    public function test_successful_check_clears_the_previous_error(): void
    {
        Http::fake(['*' => Http::response(['status' => 'healthy'])]);
        $website = $this->site();
        $website->update(['status' => 'error', 'last_error' => 'old problem']);

        app(WordPressApiClient::class)->checkHealth($website);

        $this->assertNull($website->fresh()->last_error);
        $this->assertSame('online', $website->fresh()->status);
    }

    public function test_check_connection_button_reports_the_reason(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $website = $this->site();

        $this->from(route('websites.show', $website))
            ->post(route('websites.test-all', $website))
            ->assertRedirect(route('websites.show', $website))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'installed and activated'));
    }
}
