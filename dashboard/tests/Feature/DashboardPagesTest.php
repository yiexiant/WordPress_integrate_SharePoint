<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\RestoreLog;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 12:00:00');
        $this->actingAs(User::factory()->create());
    }

    private function healthySite(array $attributes = []): Website
    {
        return Website::create(array_merge([
            'name' => 'Healthy shop', 'url' => 'https://shop.test', 'api_key' => 'k',
            'status' => 'online', 'sharepoint_status' => 'connected', 'last_sync_at' => now(),
            'backup_frequency' => 'daily', 'last_backup_at' => now()->subHours(3), 'next_backup_at' => now()->addHours(20),
        ], $attributes));
    }

    public function test_overview_summarises_fleet_health_in_plain_language(): void
    {
        $this->healthySite();
        $this->healthySite(['name' => 'Broken blog', 'url' => 'https://blog.test', 'status' => 'offline', 'last_error' => 'cURL error 6']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('1 of 2 websites needs attention')
            ->assertSeeText('Broken blog')
            ->assertSeeText('cURL error 6')
            ->assertSeeText('Healthy');
    }

    public function test_overview_says_all_good_when_nothing_needs_attention(): void
    {
        $this->healthySite();

        $this->get(route('dashboard'))->assertOk()->assertSeeText('All 1 website look good')->assertDontSeeText('Needs attention');
    }

    public function test_overview_has_an_empty_state(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSeeText('No websites connected yet');
    }

    public function test_health_explains_each_problem(): void
    {
        $this->assertSame('Unreachable', $this->healthySite(['status' => 'offline'])->health()->label);
        $this->assertSame('SharePoint error', $this->healthySite(['sharepoint_status' => 'error'])->health()->label);
        $this->assertSame('Backup overdue', $this->healthySite(['backup_overdue' => true])->health()->label);
        $this->assertSame('No automatic backups', $this->healthySite(['backup_frequency' => 'manual'])->health()->label);
        $this->assertSame('Healthy', $this->healthySite(['backup_frequency' => 'manual', 'purpose' => 'migration'])->health()->label);
        $this->assertSame('Not checked yet', $this->healthySite(['status' => 'offline', 'last_sync_at' => null])->health()->label);

        $failed = $this->healthySite();
        BackupLog::create(['website_id' => $failed->id, 'backup_id' => 'spsync-backup-20261115-020000', 'status' => 'failed']);
        $this->assertSame('Last backup failed', $failed->fresh()->health()->label);
    }

    public function test_website_list_filters_by_health(): void
    {
        $this->healthySite();
        $this->healthySite(['name' => 'Broken blog', 'url' => 'https://blog.test', 'status' => 'offline']);

        $this->get(route('websites.index', ['filter' => 'attention']))->assertOk()->assertSeeText('Broken blog')->assertDontSeeText('Healthy shop');
        $this->get(route('websites.index', ['filter' => 'healthy']))->assertOk()->assertSeeText('Healthy shop')->assertDontSeeText('Broken blog');
        $this->get(route('websites.index', ['search' => 'blog']))->assertOk()->assertSeeText('Broken blog')->assertDontSeeText('Healthy shop');
    }

    public function test_every_website_tab_renders(): void
    {
        $website = $this->healthySite(['system_info' => ['wordpress_version' => '6.7', 'php_version' => '8.3.0']]);
        $source = $this->healthySite(['name' => 'Source', 'url' => 'https://source.test', 'site_folder' => 'source.test']);
        BackupLog::create(['website_id' => $website->id, 'backup_id' => 'spsync-backup-20261114-020000', 'status' => 'completed', 'total_files' => 3, 'uploaded_files' => 3]);
        BackupLog::create(['website_id' => $source->id, 'backup_id' => 'spsync-backup-20261113-020000', 'status' => 'completed']);
        RestoreLog::create(['website_id' => $website->id, 'backup_id' => 'spsync-backup-20261114-020000', 'restore_job_id' => 'r1', 'status' => 'failed', 'error_message' => 'Checksum mismatch', 'started_at' => now()]);

        $this->get(route('websites.show', $website))->assertOk()->assertSeeText('Last successful')->assertSeeText('6.7');
        $this->get(route('websites.show', [$website, 'tab' => 'backups']))->assertOk()->assertSeeText('Sat 14 Nov 2026, 02:00')->assertSeeText('Restore');
        $this->get(route('websites.show', [$website, 'tab' => 'restores']))->assertOk()->assertSeeText('Checksum mismatch');
        $this->get(route('websites.show', [$website, 'tab' => 'migrate']))->assertOk()->assertSeeText('Fri 13 Nov 2026, 02:00')->assertSeeText('Preview changes');
        $this->get(route('websites.show', [$website, 'tab' => 'bogus']))->assertOk()->assertSeeText('Last successful');
    }

    public function test_running_job_enables_live_progress(): void
    {
        $website = $this->healthySite();
        BackupLog::create(['website_id' => $website->id, 'backup_id' => 'spsync-backup-20261115-110000', 'status' => 'uploading', 'total_files' => 4, 'uploaded_files' => 1]);

        $this->get(route('websites.show', $website))->assertOk()->assertSee('job-banner', false)->assertSee('poll-status', false);
    }

    public function test_remove_button_lives_on_the_edit_page(): void
    {
        $website = $this->healthySite();

        $this->get(route('websites.edit', $website))->assertOk()->assertSee('name="_method" value="DELETE"', false)->assertSeeText('Remove website');
        $this->get(route('websites.show', $website))->assertOk()->assertDontSee('name="_method" value="DELETE"', false);
    }

    public function test_add_website_form_renders(): void
    {
        $this->get(route('websites.create'))->assertOk()->assertSeeText('Secret API key');
    }
}
