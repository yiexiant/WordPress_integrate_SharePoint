<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncWebsitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wordpress.allow_private_hosts' => true]);
        $this->travelTo('2026-11-20 10:00:00');
    }

    private function fakeSite(string $host, string $lastBackup, string $frequency = 'daily'): void
    {
        Http::fake([
            "https://{$host}/wp-json/spsync/v1/health" => Http::response(['status' => 'healthy']),
            "https://{$host}/wp-json/spsync/v1/backup/status" => Http::response([
                'status' => 'idle',
                'restore' => ['restore_job_id' => null, 'status' => 'completed'],
                'last_successful_backup' => ['backup_id' => 'spsync-backup-x', 'created_at' => $lastBackup],
                'schedule' => ['frequency' => $frequency, 'next_run' => null],
                'site_folder' => $host,
                'plugin_version' => '1.4.0',
            ]),
        ]);
    }

    public function test_sync_flags_overdue_sites_once_and_logs_recovery(): void
    {
        $website = Website::create(['name' => 'Shop', 'url' => 'https://shop.test', 'api_key' => 'k', 'status' => 'online']);
        $this->fakeSite('shop.test', '2026-11-15T02:00:00+00:00');

        $this->artisan('sites:sync')->assertSuccessful();
        $this->artisan('sites:sync')->assertSuccessful();

        $this->assertTrue($website->fresh()->backup_overdue);
        $this->assertSame(1, ActivityLog::where('action', 'monitor')->where('level', 'danger')->count(), 'Overdue is logged once, not every run.');

        Http::swap(new Factory);
        $this->fakeSite('shop.test', '2026-11-20T02:05:00+00:00');
        $this->artisan('sites:sync')->assertSuccessful();

        $this->assertFalse($website->fresh()->backup_overdue);
        $this->assertSame(1, ActivityLog::where('action', 'monitor')->where('level', 'success')->count());
    }

    public function test_sync_records_unreachable_sites(): void
    {
        $website = Website::create(['name' => 'Down', 'url' => 'https://down.test', 'api_key' => 'k', 'status' => 'online']);
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $this->artisan('sites:sync')->assertSuccessful();

        $website->refresh();
        $this->assertNotSame('online', $website->status);
        $this->assertNotNull($website->last_error);
        $this->assertDatabaseHas('activity_logs', ['website_id' => $website->id, 'action' => 'monitor', 'level' => 'danger']);
    }

    public function test_an_unreachable_site_still_becomes_overdue(): void
    {
        $website = Website::create([
            'name' => 'Down', 'url' => 'https://down.test', 'api_key' => 'k', 'status' => 'offline',
            'backup_frequency' => 'daily', 'last_backup_at' => '2026-11-10 02:00:00',
        ]);
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $this->artisan('sites:sync')->assertSuccessful();

        $this->assertTrue($website->fresh()->backup_overdue);
    }

    public function test_sync_can_target_specific_websites(): void
    {
        $included = Website::create(['name' => 'A', 'url' => 'https://a.test', 'api_key' => 'k']);
        Website::create(['name' => 'B', 'url' => 'https://b.test', 'api_key' => 'k']);
        $this->fakeSite('a.test', '2026-11-20T02:00:00+00:00');

        $this->artisan('sites:sync', ['--website' => [$included->id]])->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'b.test'));
    }

    public function test_monitor_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

        $this->assertStringContainsString('sites:sync', $commands);
    }
}
