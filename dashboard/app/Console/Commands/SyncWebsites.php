<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\WordPressApiClient;
use Illuminate\Console\Command;

/**
 * Unattended fleet monitor: refreshes health and backup status for every registered website,
 * so problems surface even when nobody has the dashboard open. Scheduled in routes/console.php.
 */
class SyncWebsites extends Command
{
    protected $signature = 'sites:sync
                            {--website=* : Only sync these website IDs}
                            {--with-system-info : Also refresh PHP/WordPress/disk diagnostics}';

    protected $description = 'Refresh health and backup status for registered WordPress websites';

    public function handle(WordPressApiClient $client): int
    {
        $query = Website::query()->orderBy('id');
        if ($ids = $this->option('website')) {
            $query->whereIn('id', $ids);
        }

        $rows = [];
        $query->chunkById(50, function ($websites) use ($client, &$rows) {
            foreach ($websites as $website) {
                $rows[] = $this->syncOne($client, $website);
            }
        });

        $this->table(['Website', 'WordPress', 'Last backup', 'Schedule', 'Overdue'], $rows);

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function syncOne(WordPressApiClient $client, Website $website): array
    {
        $wasReachable = $website->status === 'online';
        $wasOverdue = $website->backup_overdue;

        $health = $client->checkHealth($website);
        if ($health['success']) {
            $client->getBackupStatus($website);
            if ($this->option('with-system-info')) {
                $client->getSystemInfo($website);
            }
        } else {
            // Time keeps passing while a site is down: re-evaluate freshness from what we know.
            $website->last_error = $health['message'];
            $website->backup_overdue = $website->isBackupOverdue();
            $website->save();
        }
        $website->refresh();

        $this->recordTransitions($website, $wasReachable, $wasOverdue, $health['message'] ?? '');

        return [
            $website->name,
            $website->status,
            $website->last_backup_at?->diffForHumans() ?? 'never',
            $website->backup_frequency ?? '?',
            $website->backup_overdue ? 'YES' : 'no',
        ];
    }

    /** Log only state changes so the activity feed is not flooded every few minutes. */
    private function recordTransitions(Website $website, bool $wasReachable, bool $wasOverdue, string $healthMessage): void
    {
        $isReachable = $website->status === 'online';
        if ($wasReachable && ! $isReachable) {
            $this->log($website, 'danger', "{$website->name} is unreachable: {$healthMessage}");
        } elseif (! $wasReachable && $isReachable) {
            $this->log($website, 'success', "{$website->name} is reachable again.");
        }

        if (! $wasOverdue && $website->backup_overdue) {
            $this->log($website, 'danger', sprintf(
                '%s has no successful SharePoint backup since %s (%s schedule).',
                $website->name,
                $website->last_backup_at?->toDayDateTimeString() ?? 'registration',
                $website->backup_frequency
            ));
        } elseif ($wasOverdue && ! $website->backup_overdue) {
            $this->log($website, 'success', "{$website->name} backups are up to date again.");
        }
    }

    private function log(Website $website, string $level, string $message): void
    {
        ActivityLog::create([
            'website_id' => $website->id,
            'action'     => 'monitor',
            'level'      => $level,
            'message'    => $message,
        ]);
        $this->line($message);
    }
}
