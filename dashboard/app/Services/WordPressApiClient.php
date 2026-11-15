<?php

namespace App\Services;

use App\Models\Website;
use App\Models\BackupLog;
use App\Models\RestoreLog;
use App\Models\ActivityLog;
use App\Support\SiteUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class WordPressApiClient
{
    /**
     * Build base HTTP request configured with timeouts and auth header.
     */
    protected function request(Website $website, int $timeout = 15)
    {
        $request = Http::timeout($timeout)
            ->withHeaders([
                'X-SPSYNC-KEY' => $website->api_key,
                'Accept'       => 'application/json',
            ])
            // Never forward the API key to a redirect target; register the site's canonical URL instead.
            ->withOptions(['allow_redirects' => false]);

        // TLS is verified unless explicitly disabled (default only for APP_ENV=local).
        return config('services.wordpress.verify_tls') ? $request : $request->withoutVerifying();
    }

    /**
     * Build API URL for a relative endpoint, refusing targets the deployment does not allow.
     *
     * @throws \InvalidArgumentException
     */
    protected function endpoint(Website $website, string $path): string
    {
        $baseUrl = rtrim($website->url, '/');
        SiteUrlGuard::assertAllowed($baseUrl);
        return $baseUrl . '/wp-json/spsync/v1/' . ltrim($path, '/');
    }

    /**
     * Check WordPress health endpoint.
     */
    public function checkHealth(Website $website): array
    {
        try {
            $url = $this->endpoint($website, 'health');
            $response = $this->request($website, 10)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                $website->update([
                    'status' => 'online',
                    'last_sync_at' => now(),
                ]);
                return [
                    'success' => true,
                    'status'  => 'online',
                    'data'    => $data,
                    'message' => 'WordPress site is online and responsive.',
                ];
            }

            $errMsg = $response->json('message') ?? 'HTTP ' . $response->status();
            $website->update(['status' => 'error']);
            return [
                'success' => false,
                'status'  => 'error',
                'message' => 'WordPress responded with error: ' . $errMsg,
            ];
        } catch (Exception $e) {
            $website->update(['status' => 'offline']);
            return [
                'success' => false,
                'status'  => 'offline',
                'message' => 'Could not connect to WordPress: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Test Microsoft SharePoint Connection via WordPress.
     */
    public function testSharePointConnection(Website $website): array
    {
        try {
            $url = $this->endpoint($website, 'connection/test');
            $response = $this->request($website, 30)->post($url);

            $data = $response->json() ?? [];
            $isSuccess = $response->successful() && !empty($data['success']);

            $website->update([
                'sharepoint_status' => $isSuccess ? 'connected' : 'error',
                'last_sync_at'      => now(),
            ]);

            ActivityLog::create([
                'website_id' => $website->id,
                'action'     => 'connection_test',
                'level'      => $isSuccess ? 'success' : 'danger',
                'message'    => $isSuccess ? 'SharePoint connection verified successfully.' : 'SharePoint connection test failed.',
                'context'    => $data,
            ]);

            return [
                'success' => $isSuccess,
                'message' => $data['message'] ?? ($isSuccess ? 'SharePoint connected successfully!' : 'SharePoint connection failed.'),
                'data'    => $data,
            ];
        } catch (Exception $e) {
            $website->update(['sharepoint_status' => 'error']);
            return [
                'success' => false,
                'message' => 'SharePoint Connection Test Failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch System Info from WordPress (PHP, WP version, DB size, disk, SharePoint folder).
     */
    public function getSystemInfo(Website $website): array
    {
        try {
            $url = $this->endpoint($website, 'system/info');
            $response = $this->request($website, 15)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                $website->update([
                    'system_info'  => $data,
                    'status'       => 'online',
                    'last_sync_at' => now(),
                ]);
                return [
                    'success' => true,
                    'data'    => $data,
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to retrieve system info (HTTP ' . $response->status() . ')',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get live backup status from WordPress.
     */
    public function getBackupStatus(Website $website): array
    {
        try {
            $url = $this->endpoint($website, 'backup/status');
            $response = $this->request($website, 10)->get($url);

            if ($response->successful()) {
                $data = $response->json();

                if (!empty($data['backup_id'])) {
                    // Update or create BackupLog
                    $backupLog = BackupLog::firstOrNew([
                        'website_id' => $website->id,
                        'backup_id'  => $data['backup_id'],
                    ]);

                    $backupLog->status = $data['status'] ?? 'queued';
                    $backupLog->total_files = $data['total_files'] ?? 0;
                    $backupLog->uploaded_files = $data['uploaded_files'] ?? 0;
                    $backupLog->is_removed_from_sharepoint = ($backupLog->status === 'missing');
                    $backupLog->save();

                    if ($backupLog->status === 'completed') {
                        $website->update(['last_backup_at' => now()]);
                    }
                }

                // Update RestoreLog if restore job information is returned
                if (!empty($data['restore']['restore_job_id'])) {
                    $restoreLog = RestoreLog::where('website_id', $website->id)
                        ->where('restore_job_id', $data['restore']['restore_job_id'])
                        ->first();

                    if ($restoreLog && !in_array($restoreLog->status, ['completed', 'failed'], true)) {
                        $newStatus = $data['restore']['status'] ?? $restoreLog->status;
                        $restoreLog->status = $newStatus;

                        if (in_array($newStatus, ['completed', 'failed'], true)) {
                            $restoreLog->completed_at = now();
                            if ($restoreLog->started_at) {
                                $restoreLog->duration_seconds = $restoreLog->started_at->diffInSeconds($restoreLog->completed_at);
                            }
                        }
                        $restoreLog->save();
                    }
                }

                return [
                    'success' => true,
                    'data'    => $data,
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to fetch backup status.',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger a single backup on WordPress.
     */
    public function triggerBackup(Website $website): array
    {
        try {
            $url = $this->endpoint($website, 'backup/start');
            $response = $this->request($website, 15)->post($url);

            if ($response->successful()) {
                $data = $response->json();
                $backupId = $data['backup_id'] ?? ('spsync-backup-' . now()->format('Ymd-His'));

                BackupLog::create([
                    'website_id'                 => $website->id,
                    'backup_id'                  => $backupId,
                    'status'                     => 'queued',
                    'total_files'                => 0,
                    'uploaded_files'             => 0,
                    'is_removed_from_sharepoint' => false,
                ]);

                ActivityLog::create([
                    'website_id' => $website->id,
                    'action'     => 'backup_trigger',
                    'level'      => 'info',
                    'message'    => "Backup job {$backupId} triggered successfully.",
                ]);

                return [
                    'success'   => true,
                    'backup_id' => $backupId,
                    'message'   => $data['message'] ?? 'Backup job successfully enqueued in background.',
                ];
            }

            $errorMsg = $response->json('message') ?? 'HTTP ' . $response->status();
            return [
                'success' => false,
                'message' => 'Failed to trigger backup: ' . $errorMsg,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception while triggering backup: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger a restoration on WordPress.
     */
    public function triggerRestore(Website $website, string $backupId): array
    {
        try {
            $url = $this->endpoint($website, 'restore/start');
            $response = $this->request($website, 20)->post($url, [
                'backup_id' => $backupId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $restoreJobId = $data['restore_job_id'] ?? ('spsync-restore-' . now()->format('Ymd-His'));

                $restoreLog = \App\Models\RestoreLog::create([
                    'website_id'     => $website->id,
                    'backup_id'      => $backupId,
                    'restore_job_id' => $restoreJobId,
                    'status'         => 'downloading',
                    'started_at'     => now(),
                ]);

                ActivityLog::create([
                    'website_id' => $website->id,
                    'action'     => 'restore_trigger',
                    'level'      => 'warning',
                    'message'    => "Restoration job {$restoreJobId} initiated for backup {$backupId}.",
                ]);

                return [
                    'success'        => true,
                    'restore_job_id' => $restoreJobId,
                    'restore_log_id' => $restoreLog->id,
                    'message'        => $data['message'] ?? 'Restore process initiated successfully.',
                ];
            }

            $errorMsg = $response->json('message') ?? 'HTTP ' . $response->status();
            return [
                'success' => false,
                'message' => 'Failed to start restore: ' . $errorMsg,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception while starting restore: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify Remote Backups on SharePoint and flag deleted/missing files.
     */
    public function verifySharePointBackups(Website $website): array
    {
        try {
            $url = $this->endpoint($website, 'backups/verify');
            $response = $this->request($website, 30)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                $verified = $data['verified_backups'] ?? [];
                $missing  = $data['missing_backups'] ?? [];
                $remote   = $data['remote_backups'] ?? [];

                $allRemoteVerified = array_values(array_unique(array_merge($verified, $remote)));

                // Auto-discover and register remote backups into Dashboard BackupLog
                foreach ($allRemoteVerified as $backupId) {
                    if (empty($backupId)) continue;
                    BackupLog::updateOrCreate(
                        [
                            'website_id' => $website->id,
                            'backup_id'  => $backupId,
                        ],
                        [
                            'status'                     => 'completed',
                            'is_removed_from_sharepoint' => false,
                            'verified_at'                => now(),
                        ]
                    );
                }

                // Mark deleted / missing backups on SharePoint
                if (!empty($missing)) {
                    BackupLog::where('website_id', $website->id)
                        ->whereIn('backup_id', $missing)
                        ->update([
                            'status'                     => 'missing',
                            'is_removed_from_sharepoint' => true,
                            'verified_at'                => now(),
                        ]);

                    ActivityLog::create([
                        'website_id' => $website->id,
                        'action'     => 'verification',
                        'level'      => 'warning',
                        'message'    => count($missing) . " backup(s) were detected as deleted/removed from SharePoint.",
                        'context'    => ['missing_backups' => $missing],
                    ]);
                }

                return [
                    'success'          => true,
                    'verified_count'   => count($allRemoteVerified),
                    'missing_count'    => count($missing),
                    'missing_backups'  => $missing,
                    'message'          => sprintf(
                        'Remote verification complete: %d verified on SharePoint, %d missing/removed.',
                        count($allRemoteVerified),
                        count($missing)
                    ),
                ];
            }

            return [
                'success' => false,
                'message' => 'Verification failed: ' . ($response->json('message') ?? 'HTTP ' . $response->status()),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception during remote verification: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch raw execution logs from WordPress.
     */
    public function fetchLogs(Website $website, int $limit = 50): array
    {
        try {
            $url = $this->endpoint($website, 'logs?limit=' . $limit);
            $response = $this->request($website, 10)->get($url);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'logs'    => $response->json() ?? [],
                ];
            }

            return [
                'success' => false,
                'logs'    => [],
                'message' => 'Could not fetch logs.',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'logs'    => [],
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger database URL search-and-replace on the WordPress website.
     *
     * @param Website $website
     * @param string  $oldUrl
     * @param string  $newUrl
     * @param bool    $dryRun
     * @return array
     */
    public function updateDatabaseUrl(Website $website, string $oldUrl, string $newUrl, bool $dryRun = false): array
    {
        try {
            $url = $this->endpoint($website, 'database/search-replace');
            $response = $this->request($website, 60)->post($url, [
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'dry_run' => $dryRun ? 'true' : 'false',
            ]);

            $data = $response->json() ?? [];

            if ($response->successful() && !empty($data['success'])) {
                ActivityLog::create([
                    'website_id' => $website->id,
                    'action'     => 'url_update',
                    'level'      => $dryRun ? 'info' : 'success',
                    'message'    => $dryRun
                        ? "Dry Run URL Search & Replace: '{$oldUrl}' -> '{$newUrl}'. Matches found: " . ($data['replacements_count'] ?? 0)
                        : "Database URL Search & Replace Applied: '{$oldUrl}' -> '{$newUrl}'. Rows updated: " . ($data['rows_updated'] ?? 0),
                    'context'    => $data,
                ]);

                return [
                    'success' => true,
                    'data'    => $data,
                    'message' => $data['message'] ?? 'Database URL update completed successfully.',
                ];
            }

            $errMsg = $data['message'] ?? ($response->json('message') ?? 'HTTP ' . $response->status());
            return [
                'success' => false,
                'message' => 'URL update failed: ' . $errMsg,
                'data'    => $data,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception during database URL update: ' . $e->getMessage(),
            ];
        }
    }
}
