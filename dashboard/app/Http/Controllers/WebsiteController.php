<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\BackupLog;
use App\Models\RestoreLog;
use App\Models\ActivityLog;
use App\Rules\AllowedSiteUrl;
use App\Services\WordPressApiClient;
use Illuminate\Http\Request;

class WebsiteController extends Controller
{
    protected WordPressApiClient $client;

    public function __construct(WordPressApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Display a listing of all websites with bulk selection options.
     */
    public function index(Request $request)
    {
        $query = Website::with(['latestBackup', 'latestRestore'])->withCount('backupLogs');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $websites = $query->orderBy('name', 'asc')->paginate(15)->withQueryString();

        return view('websites.index', compact('websites'));
    }

    /**
     * Show the form for creating a new website.
     */
    public function create()
    {
        return view('websites.create');
    }

    /**
     * Store a newly created website in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'purpose' => 'nullable|string|in:backup,migration',
            'url'     => ['required', 'url:http,https', 'max:255', new AllowedSiteUrl],
            'api_key' => 'required|string|max:255',
        ]);

        $validated['url'] = rtrim($validated['url'], '/');
        $validated['purpose'] = $validated['purpose'] ?? 'backup';
        $website = Website::create($validated);

        // Auto-run initial health check and system info sync
        $this->client->checkHealth($website);
        $this->client->getSystemInfo($website);

        ActivityLog::create([
            'website_id' => $website->id,
            'action'     => 'website_created',
            'level'      => 'success',
            'message'    => "Website '{$website->name}' registered into the dashboard as " . ($website->isMigration() ? 'Migration Target' : 'Routine Backup') . ".",
        ]);

        return redirect()->route('websites.show', $website)
            ->with('success', "Website '{$website->name}' added successfully! Initial health check performed.");
    }

    /**
     * Display the specified website details, system info, backup logs, and restore duration.
     */
    public function show(Website $website)
    {
        $website->load(['backupLogs', 'restoreLogs', 'activityLogs']);

        $systemInfo = $website->system_info ?? [];
        $backupLogs = $website->backupLogs()->paginate(10, ['*'], 'backups_page');
        $restoreLogs = $website->restoreLogs()->paginate(10, ['*'], 'restores_page');

        // Stats for this website
        $totalBackups = $website->backupLogs()->count();
        $successfulBackups = $website->backupLogs()->where('status', 'completed')->where('is_removed_from_sharepoint', false)->count();
        // Grouped so the OR cannot escape this website's scope.
        $missingBackups = $website->backupLogs()
            ->where(fn ($query) => $query->where('is_removed_from_sharepoint', true)->orWhere('status', 'missing'))
            ->count();

        // Other registered websites for URL update suggestions
        $otherWebsites = Website::where('id', '!=', $website->id)->orderBy('name', 'asc')->get();

        // Websites whose completed SharePoint backups can be restored here (cross-site migration).
        $migrationSources = Website::where('id', '!=', $website->id)
            ->whereNotNull('site_folder')
            ->whereHas('backupLogs', fn ($query) => $query->where('status', 'completed')->where('is_removed_from_sharepoint', false))
            ->with(['backupLogs' => fn ($query) => $query->where('status', 'completed')->where('is_removed_from_sharepoint', false)->limit(20)])
            ->orderBy('name')
            ->get();

        return view('websites.show', compact(
            'website',
            'systemInfo',
            'backupLogs',
            'restoreLogs',
            'totalBackups',
            'successfulBackups',
            'missingBackups',
            'otherWebsites',
            'migrationSources'
        ));
    }

    /**
     * Show the form for editing the specified website.
     */
    public function edit(Website $website)
    {
        return view('websites.edit', compact('website'));
    }

    /**
     * Update the specified website in storage.
     */
    public function update(Request $request, Website $website)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'purpose' => 'nullable|string|in:backup,migration',
            'url'     => ['required', 'url:http,https', 'max:255', new AllowedSiteUrl],
            'api_key' => 'nullable|string|max:255',
        ]);

        $validated['url'] = rtrim($validated['url'], '/');
        // The stored key is never sent to the browser; a blank field keeps it.
        if (blank($validated['api_key'] ?? null)) {
            unset($validated['api_key']);
        }
        $website->update($validated);

        return redirect()->route('websites.show', $website)
            ->with('success', "Website '{$website->name}' updated successfully.");
    }

    /**
     * Remove the specified website from storage.
     */
    public function destroy(Website $website)
    {
        $name = $website->name;
        $website->delete();

        return redirect()->route('websites.index')
            ->with('success', "Website '{$name}' deleted successfully.");
    }

    /**
     * Test WordPress connection and sync system info.
     */
    public function testWordPressConnection(Website $website)
    {
        $health = $this->client->checkHealth($website);
        $sysInfo = $this->client->getSystemInfo($website);

        if ($health['success']) {
            return back()->with('success', 'WordPress API connection verified! System diagnostics refreshed.');
        }

        return back()->with('error', 'WordPress connection failed: ' . ($health['message'] ?? 'Unknown error'));
    }

    /**
     * Test SharePoint Connection & OAuth credentials.
     */
    public function testSharePointConnection(Website $website)
    {
        $result = $this->client->testSharePointConnection($website);

        if ($result['success']) {
            return back()->with('success', 'SharePoint connected & verified: ' . $result['message']);
        }

        return back()->with('error', 'SharePoint connection error: ' . $result['message']);
    }

    /**
     * Verify Remote Backups on SharePoint (Requirement 5).
     * Scans SharePoint items; if backup file was deleted from SharePoint, marks it as "Removed from SharePoint".
     */
    public function verifyRemoteBackups(Website $website)
    {
        $result = $this->client->verifySharePointBackups($website);

        if ($result['success']) {
            $msg = $result['message'];
            if (!empty($result['missing_count']) && $result['missing_count'] > 0) {
                return back()->with('warning', $msg . ' Missing backups are flagged as "Removed from SharePoint".');
            }
            return back()->with('success', $msg);
        }

        return back()->with('error', 'SharePoint verification failed: ' . $result['message']);
    }

    /**
     * Combined Connection Test (Tests WP, SharePoint, and Remote Storage Files in one click).
     */
    public function testAllConnections(Website $website)
    {
        $health = $this->client->checkHealth($website);
        if (!$health['success']) {
            return back()->with('error', 'WordPress Connection Failed: ' . $health['message']);
        }

        $this->client->getSystemInfo($website);
        $sp = $this->client->testSharePointConnection($website);
        $verify = $this->client->verifySharePointBackups($website);

        $messages = [];
        $messages[] = "WordPress: Online";
        $messages[] = "SharePoint: " . ($sp['success'] ? 'Connected' : 'Failed');
        if ($verify['success']) {
            $messages[] = "Remote Storage: " . $verify['message'];
        }

        $flashType = ($sp['success'] && $verify['success']) ? 'success' : 'warning';
        return back()->with($flashType, implode(' | ', $messages));
    }

    /**
     * Manually trigger a backup on a single website.
     */
    public function triggerBackup(Website $website)
    {
        $result = $this->client->triggerBackup($website);

        if ($result['success']) {
            return back()->with('success', "Backup triggered successfully! Job ID: {$result['backup_id']}. Progress is syncing.");
        }

        return back()->with('error', 'Backup trigger failed: ' . $result['message']);
    }

    /**
     * Bulk trigger backups on all checked websites (Requirement 3).
     */
    public function bulkTriggerBackup(Request $request)
    {
        $request->validate([
            'website_ids'   => 'required|array|min:1',
            'website_ids.*' => 'exists:websites,id',
        ]);

        $websiteIds = $request->input('website_ids');
        $websites = Website::whereIn('id', $websiteIds)->get();

        $successCount = 0;
        $failCount = 0;
        $failedSites = [];

        foreach ($websites as $website) {
            $result = $this->client->triggerBackup($website);
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                $failedSites[] = $website->name;
            }
        }

        ActivityLog::create([
            'action'  => 'bulk_backup',
            'level'   => $failCount === 0 ? 'success' : 'warning',
            'message' => "Bulk backup triggered for {$successCount} website(s)." . ($failCount > 0 ? " ({$failCount} failed)" : ""),
            'context' => [
                'total_requested' => count($websiteIds),
                'successful'      => $successCount,
                'failed'          => $failCount,
                'failed_sites'    => $failedSites,
            ],
        ]);

        if ($failCount === 0) {
            return redirect()->route('websites.index')
                ->with('success', "Bulk backup successfully triggered for all {$successCount} selected website(s)!");
        }

        return redirect()->route('websites.index')
            ->with('warning', "Bulk backup triggered for {$successCount} site(s). Failed on {$failCount} site(s): " . implode(', ', $failedSites));
    }

    /**
     * Trigger a restoration process from a selected backup ID.
     */
    public function triggerRestore(Request $request, Website $website)
    {
        $validated = $request->validate([
            'backup_id'         => ['required', 'string', 'regex:/^spsync-backup-[A-Za-z0-9_-]{1,80}$/'],
            'source_website_id' => ['nullable', 'integer', 'exists:websites,id'],
        ]);

        // Migration: restore a backup that another registered website stored in its own SharePoint folder.
        $source = isset($validated['source_website_id']) ? Website::find($validated['source_website_id']) : null;

        $result = $this->client->triggerRestore($website, $validated['backup_id'], $source);

        if ($result['success']) {
            return back()->with('success', "Restore job {$result['restore_job_id']} started! Duration and progress are tracked in the Restore History table.");
        }

        return back()->with('error', 'Restore trigger failed: ' . $result['message']);
    }

    /**
     * Poll live status of backup and restore operations for real-time frontend updates.
     */
    public function pollStatus(Website $website)
    {
        $backupStatus = $this->client->getBackupStatus($website);
        $website->refresh();

        $latestBackup = $website->latestBackup;
        $latestRestore = $website->latestRestore;

        return response()->json([
            'success'        => $backupStatus['success'],
            'message'        => $backupStatus['message'] ?? null,
            'backup_overdue' => $website->backup_overdue,
            'website_status' => $website->status,
            'last_sync_at'   => $website->last_sync_at ? $website->last_sync_at->diffForHumans() : 'Never',
            'latest_backup'  => $latestBackup ? [
                'backup_id'                  => $latestBackup->backup_id,
                'status'                     => $latestBackup->status,
                'total_files'                => $latestBackup->total_files,
                'uploaded_files'             => $latestBackup->uploaded_files,
                'is_removed_from_sharepoint' => $latestBackup->is_removed_from_sharepoint,
                'created_at'                 => $latestBackup->created_at->format('Y-m-d H:i:s'),
            ] : null,
            'latest_restore' => $latestRestore ? [
                'restore_job_id'             => $latestRestore->restore_job_id,
                'status'                     => $latestRestore->status,
                'duration'                   => $latestRestore->formatted_duration,
                'started_at'                 => $latestRestore->started_at ? $latestRestore->started_at->format('H:i:s') : null,
            ] : null,
        ]);
    }

    /**
     * Trigger database URL search and replace (migration tool).
     */
    public function updateDatabaseUrl(Request $request, Website $website)
    {
        $validated = $request->validate([
            'old_url' => 'required|url|max:255',
            'new_url' => 'required|url|max:255',
            'dry_run' => 'nullable|boolean',
        ]);

        $dryRun = $request->boolean('dry_run');
        $result = $this->client->updateDatabaseUrl($website, $validated['old_url'], $validated['new_url'], $dryRun);

        if ($result['success']) {
            $flashType = $dryRun ? 'info' : 'success';
            return back()->with($flashType, $result['message'])->with('url_update_result', $result['data'] ?? []);
        }

        return back()->with('error', $result['message']);
    }
}
