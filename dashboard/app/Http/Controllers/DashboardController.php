<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BackupLog;
use App\Models\RestoreLog;
use App\Models\Website;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // Every site with its plain-language health, most urgent first.
        $websites = Website::with(['latestBackup'])
            ->orderBy('name')
            ->get()
            ->map(fn (Website $website) => tap($website, fn ($site) => $site->setAttribute('healthState', $site->health())))
            ->sortBy(fn (Website $website) => [$website->healthState->rank(), strtolower($website->name)])
            ->values();

        $attention = $websites->filter(fn (Website $website) => $website->healthState->needsAttention())->values();

        $runningJobs = BackupLog::whereIn('status', ['queued', 'uploading'])->count()
            + RestoreLog::whereIn('status', ['pending', 'downloading', 'extracting', 'restoring_database', 'cleanup'])->count();

        $backedUpToday = $websites->filter(fn (Website $website) => $website->last_backup_at?->gte(now()->subDay()))->count();

        $recentActivities = ActivityLog::with('website')->latest('id')->limit(8)->get();

        return view('dashboard', [
            'websites' => $websites,
            'attention' => $attention,
            'healthyCount' => $websites->filter(fn (Website $website) => $website->healthState->level === 'ok')->count(),
            'runningJobs' => $runningJobs,
            'backedUpToday' => $backedUpToday,
            'recentActivities' => $recentActivities,
        ]);
    }
}
