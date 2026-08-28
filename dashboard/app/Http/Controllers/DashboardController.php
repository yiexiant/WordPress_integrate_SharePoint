<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\BackupLog;
use App\Models\RestoreLog;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $totalWebsites = Website::count();
        $onlineWebsites = Website::where('status', 'online')->count();
        
        // KPI: Total backups currently synced and verified on SharePoint
        $successfulBackups = BackupLog::where('status', 'completed')
            ->where('is_removed_from_sharepoint', false)
            ->count();

        // KPI: Backups detected as removed/deleted from SharePoint
        $removedFromSharePoint = BackupLog::where('is_removed_from_sharepoint', true)
            ->orWhere('status', 'missing')
            ->count();

        $activeJobsCount = BackupLog::whereIn('status', ['queued', 'uploading'])->count()
            + RestoreLog::whereIn('status', ['pending', 'downloading', 'extracting', 'restoring_database'])->count();

        $totalRestores = RestoreLog::count();
        $successfulRestores = RestoreLog::where('status', 'completed')->count();

        $websites = Website::with(['latestBackup', 'latestRestore'])
            ->orderBy('name', 'asc')
            ->get();

        $recentActivities = ActivityLog::with('website')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        $recentBackups = BackupLog::with('website')
            ->orderBy('id', 'desc')
            ->limit(8)
            ->get();

        $recentRestores = RestoreLog::with('website')
            ->orderBy('id', 'desc')
            ->limit(8)
            ->get();

        return view('dashboard', compact(
            'totalWebsites',
            'onlineWebsites',
            'successfulBackups',
            'removedFromSharePoint',
            'activeJobsCount',
            'totalRestores',
            'successfulRestores',
            'websites',
            'recentActivities',
            'recentBackups',
            'recentRestores'
        ));
    }
}
