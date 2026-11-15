<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\RestoreLog;
use App\Models\User;
use App\Models\Website;
use App\Services\WordPressApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wordpress.allow_private_hosts' => true]);
        $this->actingAs(User::factory()->create());
    }

    private function site(array $attributes = []): Website
    {
        return Website::create(array_merge([
            'name' => 'Shop', 'url' => 'https://shop.test', 'api_key' => 'k', 'status' => 'online',
        ], $attributes));
    }

    private function pluginStatus(array $overrides = []): array
    {
        return array_replace_recursive([
            'backup_id' => 'spsync-backup-20261114-020000',
            'status' => 'completed',
            'total_files' => 3,
            'uploaded_files' => 3,
            'restore' => ['restore_job_id' => null, 'status' => 'completed', 'backup_id' => null, 'error' => null],
            'last_successful_backup' => ['backup_id' => 'spsync-backup-20261114-020000', 'created_at' => '2026-11-14T02:05:00+00:00'],
            'schedule' => ['frequency' => 'daily', 'next_run' => '2026-11-16T02:00:00+00:00'],
            'site_folder' => 'shop.test',
            'plugin_version' => '1.4.0',
        ], $overrides);
    }

    public function test_status_sync_uses_the_plugins_backup_time_and_schedule(): void
    {
        $this->travelTo('2026-11-14 20:00:00');
        Http::fake(['*' => Http::response($this->pluginStatus())]);
        $website = $this->site();

        $this->assertTrue(app(WordPressApiClient::class)->getBackupStatus($website)['success']);

        $website->refresh();
        $this->assertSame('2026-11-14 02:05:00', $website->last_backup_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('daily', $website->backup_frequency);
        $this->assertSame('shop.test', $website->site_folder);
        $this->assertSame('1.4.0', $website->plugin_version);
        $this->assertFalse($website->backup_overdue);
    }

    public function test_a_daily_site_without_a_recent_backup_is_overdue(): void
    {
        $this->travelTo('2026-11-20 10:00:00');
        Http::fake(['*' => Http::response($this->pluginStatus())]);
        $website = $this->site();

        app(WordPressApiClient::class)->getBackupStatus($website);

        $this->assertTrue($website->fresh()->backup_overdue);
    }

    public function test_manual_sites_are_never_overdue(): void
    {
        $this->travelTo('2027-01-01 00:00:00');
        Http::fake(['*' => Http::response($this->pluginStatus(['schedule' => ['frequency' => 'manual', 'next_run' => null]]))]);
        $website = $this->site();

        app(WordPressApiClient::class)->getBackupStatus($website);

        $this->assertFalse($website->fresh()->backup_overdue);
    }

    public function test_restore_failure_reason_is_recorded_even_for_restores_started_in_wordpress(): void
    {
        $website = $this->site();
        Http::fake(['*' => Http::sequence()
            ->push($this->pluginStatus(['restore' => ['restore_job_id' => 'spsync-local-1', 'status' => 'extracting', 'backup_id' => 'spsync-backup-x']]))
            ->push($this->pluginStatus(['restore' => ['restore_job_id' => 'spsync-local-1', 'status' => 'failed', 'error' => 'Checksum mismatch']]))]);
        $client = app(WordPressApiClient::class);

        $client->getBackupStatus($website);
        $client->getBackupStatus($website);

        $restore = RestoreLog::where('restore_job_id', 'spsync-local-1')->sole();
        $this->assertSame('failed', $restore->status);
        $this->assertSame('Checksum mismatch', $restore->error_message);
        $this->assertNotNull($restore->completed_at);
    }

    public function test_poll_reports_failure_honestly(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope'], 500)]);
        $website = $this->site();

        $this->getJson(route('websites.poll-status', $website))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNotNull($website->fresh()->last_error);
    }

    public function test_verification_flags_incomplete_backups(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'site_folder' => 'shop.test',
            'verified_backups' => ['spsync-backup-a'],
            'incomplete_backups' => ['spsync-backup-b'],
            'missing_backups' => ['spsync-backup-c'],
            'remote_backups' => ['spsync-backup-a', 'spsync-backup-b'],
        ])]);
        $website = $this->site();
        BackupLog::create(['website_id' => $website->id, 'backup_id' => 'spsync-backup-c', 'status' => 'completed']);

        $result = app(WordPressApiClient::class)->verifySharePointBackups($website);

        $this->assertSame(1, $result['verified_count']);
        $this->assertSame('completed', BackupLog::where('backup_id', 'spsync-backup-a')->value('status'));
        $this->assertSame('incomplete', BackupLog::where('backup_id', 'spsync-backup-b')->value('status'));
        $this->assertSame('missing', BackupLog::where('backup_id', 'spsync-backup-c')->value('status'));
    }

    public function test_missing_count_on_details_page_is_scoped_to_the_website(): void
    {
        $website = $this->site();
        $other = $this->site(['name' => 'Other', 'url' => 'https://other.test']);
        BackupLog::create(['website_id' => $other->id, 'backup_id' => 'spsync-backup-o', 'status' => 'missing']);

        $this->get(route('websites.show', $website))->assertOk()->assertViewHas('missingBackups', 0);
    }

    public function test_cross_site_restore_sends_the_source_site_folder(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'restore_job_id' => 'spsync-restore-1'], 202)]);
        $target = $this->site(['name' => 'Staging', 'url' => 'https://staging.test']);
        $source = $this->site(['site_folder' => 'shop.test']);

        $this->post(route('websites.trigger-restore', $target), [
            'backup_id' => 'spsync-backup-20261114-020000',
            'source_website_id' => $source->id,
        ])->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->url() === 'https://staging.test/wp-json/spsync/v1/restore/start'
            && $request['source_site_folder'] === 'shop.test'
            && $request['backup_id'] === 'spsync-backup-20261114-020000');
    }

    public function test_cross_site_restore_requires_a_known_source_folder(): void
    {
        Http::fake();
        $target = $this->site(['name' => 'Staging', 'url' => 'https://staging.test']);
        $source = $this->site();

        $this->post(route('websites.trigger-restore', $target), [
            'backup_id' => 'spsync-backup-20261114-020000',
            'source_website_id' => $source->id,
        ])->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_restore_rejects_malformed_backup_ids(): void
    {
        Http::fake();
        $website = $this->site();

        $this->post(route('websites.trigger-restore', $website), ['backup_id' => '../../etc'])->assertSessionHasErrors('backup_id');
        Http::assertNothingSent();
    }
}
