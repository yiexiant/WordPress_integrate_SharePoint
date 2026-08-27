@extends('layouts.app')

@section('title', $website->name . ' - Site Details')

@section('content')
<div class="space-y-8">
    <!-- Top Site Header & Status Bar -->
    <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">{{ $website->name }}</h1>
                
                <!-- WP Status Badge -->
                @if($website->status === 'online')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        WordPress Online
                    </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200">
                        WordPress Offline
                    </span>
                @endif

                <!-- SharePoint Status Badge -->
                @if($website->sharepoint_status === 'connected')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-sky-50 text-sky-700 border border-sky-200">
                        SharePoint Connected
                    </span>
                @elseif($website->sharepoint_status === 'error')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200">
                        SharePoint Auth Error
                    </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                        SharePoint Unchecked
                    </span>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-4 mt-2 text-xs text-slate-500">
                <a href="{{ $website->url }}" target="_blank" class="text-slate-700 hover:text-red-600 font-mono font-medium underline">
                    {{ $website->url }}
                </a>
                <span>•</span>
                <span>Last Synced: <strong class="text-slate-800" id="last-sync-time">{{ $website->last_sync_at ? $website->last_sync_at->diffForHumans() : 'Never' }}</strong></span>
            </div>
        </div>

        <!-- Header Actions -->
        <div class="flex flex-wrap items-center gap-2.5">
            <a href="{{ route('websites.edit', $website) }}" class="px-3.5 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition">
                Settings
            </a>
            <form method="POST" action="{{ route('websites.destroy', $website) }}" class="inline" onsubmit="return confirm('Are you sure you want to remove this website from the dashboard?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-3.5 py-2 rounded-lg text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 border border-red-200 transition">
                    Delete Website
                </button>
            </form>
        </div>
    </div>

    <!-- 🔌 Connection Diagnostics & Action Toolbar (Requirement 2 & 5) -->
    <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">
                    Connection Diagnostics & Remote Verification
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">Test individual connections or perform deep SharePoint file scans to detect deleted backups.</p>
            </div>

            <!-- Action Buttons Group (Clean White & Red buttons, no icons) -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Test WordPress Connection -->
                <form method="POST" action="{{ route('websites.test-wp', $website) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-3.5 py-2 rounded-lg text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition">
                        Test WP Connection
                    </button>
                </form>

                <!-- Verify Remote Backups (Scans for missing/deleted SharePoint files) -->
                <form method="POST" action="{{ route('websites.verify-backups', $website) }}" class="inline">
                    @csrf
                    <button type="submit" title="Scan SharePoint root folder to detect deleted backup files" class="px-3.5 py-2 rounded-lg text-xs font-bold text-amber-900 bg-amber-50 hover:bg-amber-100 border border-amber-300 transition">
                        Verify SharePoint Files
                    </button>
                </form>

                <!-- Trigger Single Backup Now (Clean Red with Confirmation) -->
                <button type="button" onclick="openBackupConfirmModal('{{ route('websites.trigger-backup', $website) }}', '{{ addslashes($website->name) }}')" class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                    Run Backup Now
                </button>
            </div>
        </div>
    </div>

    <!-- 💻 Website System Info Grid (Requirement 2/3) -->
    <div class="space-y-4">
        <h2 class="text-lg font-bold text-slate-900">
            Website System Diagnostics & Environment
        </h2>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            <!-- WP Version -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">WordPress</span>
                <span class="text-base font-extrabold text-slate-900 mt-1 block font-mono">
                    {{ $systemInfo['wordpress_version'] ?? 'N/A' }}
                </span>
                <span class="text-[10px] text-slate-400 mt-1 block">Core Engine</span>
            </div>

            <!-- PHP Version -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">PHP Version</span>
                <span class="text-base font-extrabold text-slate-900 mt-1 block font-mono">
                    {{ $systemInfo['php_version'] ?? 'N/A' }}
                </span>
                <span class="text-[10px] text-slate-400 mt-1 block">Runtime</span>
            </div>

            <!-- Memory Limit -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">Memory Limit</span>
                <span class="text-base font-extrabold text-slate-900 mt-1 block font-mono">
                    {{ $systemInfo['memory_limit'] ?? 'N/A' }}
                </span>
                <span class="text-[10px] text-slate-400 mt-1 block">RAM Limit</span>
            </div>

            <!-- Upload Limit -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">Upload Max</span>
                <span class="text-base font-extrabold text-slate-900 mt-1 block font-mono">
                    {{ $systemInfo['upload_max'] ?? 'N/A' }}
                </span>
                <span class="text-[10px] text-slate-400 mt-1 block">Max Upload</span>
            </div>

            <!-- Database Size -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm border-l-4 border-l-red-600">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">Database Size</span>
                <span class="text-base font-extrabold text-red-600 mt-1 block font-mono">
                    {{ $systemInfo['database_size'] ?? 'N/A' }}
                </span>
                <span class="text-[10px] text-slate-400 mt-1 block">MySQL Storage</span>
            </div>

            <!-- SharePoint Folder -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">Remote Folder</span>
                <span class="text-xs font-bold text-slate-800 mt-1 block truncate font-mono" title="{{ $systemInfo['sharepoint_folder'] ?? 'WordPress Backups' }}">
                    {{ $systemInfo['sharepoint_folder'] ?? 'WordPress Backups' }}
                </span>
                <span class="text-[10px] text-slate-400 mt-1 block">SharePoint Root</span>
            </div>
        </div>

        <!-- Disk Space Usage Bar (if available) -->
        @if(isset($systemInfo['disk']['free_format']))
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex items-center justify-between text-xs text-slate-700">
                <span class="font-medium">
                    Server Disk Space: <strong class="text-slate-900">{{ $systemInfo['disk']['free_format'] }} Free</strong> of {{ $systemInfo['disk']['total_format'] ?? 'Total' }}
                </span>
                <span class="text-slate-400 font-mono text-[11px]">Server Storage</span>
            </div>
        @endif
    </div>

    <!-- 📦 Backup History & SharePoint Sync State (Requirement 5) -->
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-slate-900">
                Backup Archives & Cloud Verification
            </h2>
            <span class="text-xs font-semibold text-slate-500">{{ $totalBackups }} Total Backups recorded</span>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase font-semibold text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-5 py-3.5">Backup Identifier</th>
                            <th class="px-4 py-3.5">Files / Parts</th>
                            <th class="px-4 py-3.5">SharePoint Cloud Status</th>
                            <th class="px-4 py-3.5">Created At</th>
                            <th class="px-5 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($backupLogs as $backup)
                            <tr class="hover:bg-slate-50/80 transition">
                                <!-- Backup ID -->
                                <td class="px-5 py-4 font-mono text-xs font-bold text-slate-900">
                                    {{ $backup->backup_id }}
                                </td>

                                <!-- Files Uploaded -->
                                <td class="px-4 py-4 text-xs font-mono">
                                    @if($backup->total_files > 0)
                                        <span class="text-slate-800 font-semibold">{{ $backup->uploaded_files }} / {{ $backup->total_files }}</span> files
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>

                                <!-- SharePoint Cloud Status & "Removed from SharePoint" (Requirement 5) -->
                                <td class="px-4 py-4">
                                    @if($backup->is_removed_from_sharepoint || $backup->status === 'missing')
                                        <!-- ⚠️ REMOVED FROM SHAREPOINT BADGE -->
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-900 border border-amber-300" title="This backup file is no longer present on SharePoint storage.">
                                            Removed from SharePoint
                                        </span>
                                    @elseif($backup->status === 'completed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Synced to SharePoint
                                        </span>
                                    @elseif($backup->status === 'uploading' || $backup->status === 'queued')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                                            Uploading ({{ $backup->uploaded_files }}/{{ $backup->total_files }})
                                        </span>
                                    @elseif($backup->status === 'failed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200">
                                            Upload Failed
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                                            {{ ucfirst($backup->status) }}
                                        </span>
                                    @endif
                                </td>

                                <!-- Created Timestamp -->
                                <td class="px-4 py-4 text-xs text-slate-600 font-mono">
                                    {{ $backup->created_at->format('Y-m-d H:i:s') }}
                                    <span class="text-[11px] text-slate-400 block">({{ $backup->created_at->diffForHumans() }})</span>
                                </td>

                                <!-- Action (Restore) -->
                                <td class="px-5 py-4 text-right">
                                    @if(!$backup->is_removed_from_sharepoint && $backup->status !== 'missing')
                                        <button type="button" onclick="openRestoreModal('{{ $backup->backup_id }}')" class="px-3 py-1.5 rounded-lg text-xs font-bold text-slate-800 bg-slate-100 hover:bg-slate-200 border border-slate-300 transition">
                                            Restore Site
                                        </button>
                                    @else
                                        <span class="text-xs text-slate-400 italic">Unavailable (Missing on cloud)</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-slate-400 text-xs italic">
                                    No backups recorded for this website yet. Click "Run Backup Now" to create your first archive.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($backupLogs->hasPages())
                <div class="p-4 border-t border-slate-200 bg-slate-50">
                    {{ $backupLogs->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- ⏱️ Restoration History & Restoration Duration (Requirement 4) -->
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-slate-900">
                Restoration History & Performance Metrics
            </h2>
            <span class="text-xs font-semibold text-slate-500">Tracks restoration duration & rollback status</span>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase font-semibold text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-5 py-3.5">Restore Job ID</th>
                            <th class="px-4 py-3.5">Source Backup</th>
                            <th class="px-4 py-3.5">Status</th>
                            <th class="px-4 py-3.5">Restoration Duration</th>
                            <th class="px-5 py-3.5">Executed At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($restoreLogs as $restore)
                            <tr class="hover:bg-slate-50/80 transition">
                                <!-- Job ID -->
                                <td class="px-5 py-4 font-mono text-xs font-bold text-slate-900">
                                    {{ $restore->restore_job_id }}
                                </td>

                                <!-- Source Backup ID -->
                                <td class="px-4 py-4 font-mono text-xs text-slate-600">
                                    {{ $restore->backup_id }}
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-4">
                                    @if($restore->status === 'completed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Restored Successfully
                                        </span>
                                    @elseif($restore->status === 'failed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200" title="{{ $restore->error_message }}">
                                            Rollback Triggered
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-300">
                                            {{ ucfirst(str_replace('_', ' ', $restore->status)) }}
                                        </span>
                                    @endif
                                </td>

                                <!-- ⏱️ Restoration Duration Metric (Requirement 4) -->
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold font-mono bg-red-50 text-red-800 border border-red-200">
                                        {{ $restore->formatted_duration }}
                                    </span>
                                </td>

                                <!-- Executed Timestamp -->
                                <td class="px-5 py-4 text-xs text-slate-600 font-mono">
                                    {{ $restore->created_at->format('Y-m-d H:i:s') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-slate-400 text-xs italic">
                                    No restoration operations performed for this website.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($restoreLogs->hasPages())
                <div class="p-4 border-t border-slate-200 bg-slate-50">
                    {{ $restoreLogs->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Modal: Confirm Restore Operation -->
<div id="restore-modal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 border border-slate-200 shadow-2xl space-y-5">
        <div>
            <h3 class="text-base font-bold text-slate-900">Confirm Site Restoration</h3>
            <span class="text-xs text-slate-500">Safety Snapshot will be captured automatically before restore</span>
        </div>

        <p class="text-xs text-slate-700 leading-relaxed">
            You are about to restore <strong class="text-slate-900">{{ $website->name }}</strong> to backup checkpoint <strong id="modal-backup-id-label" class="text-red-600 font-mono"></strong>.
            The plugin will stream files directly from SharePoint, extract archives, and restore database tables.
        </p>

        <form method="POST" action="{{ route('websites.trigger-restore', $website) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="backup_id" id="modal-backup-id-input" value="">

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeRestoreModal()" class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 transition">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                    Start Restoration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Confirm Backup Operation -->
<div id="backup-confirm-modal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 border border-slate-200 shadow-2xl space-y-5">
        <div>
            <h3 class="text-base font-bold text-slate-900">Confirm Immediate Backup</h3>
            <span class="text-xs text-slate-500">Manual backup execution confirmation</span>
        </div>

        <p class="text-xs text-slate-700 leading-relaxed">
            Are you sure you want to run a backup now for <strong id="backup-modal-site-name" class="text-slate-900"></strong>?
            This will dump MySQL tables, compress files into split ZIP archives, and upload them to Microsoft SharePoint in the background.
        </p>

        <form id="backup-confirm-form" method="POST" action="" class="space-y-4">
            @csrf
            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeBackupConfirmModal()" class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 transition">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                    Yes, Run Backup Now
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openBackupConfirmModal(actionUrl, siteName) {
        document.getElementById('backup-confirm-form').action = actionUrl;
        document.getElementById('backup-modal-site-name').textContent = siteName;
        const modal = document.getElementById('backup-confirm-modal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeBackupConfirmModal() {
        const modal = document.getElementById('backup-confirm-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openRestoreModal(backupId) {
        document.getElementById('modal-backup-id-input').value = backupId;
        document.getElementById('modal-backup-id-label').textContent = backupId;
        const modal = document.getElementById('restore-modal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeRestoreModal() {
        const modal = document.getElementById('restore-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Live background polling every 5 seconds for running backup / restore jobs
    document.addEventListener('DOMContentLoaded', () => {
        const pollUrl = "{{ route('websites.poll-status', $website) }}";
        let isPolling = false;

        async function pollStatus() {
            if (isPolling) return;
            isPolling = true;
            try {
                const res = await fetch(pollUrl);
                if (res.ok) {
                    const data = await res.json();
                    if (data.last_sync_at) {
                        const syncEl = document.getElementById('last-sync-time');
                        if (syncEl) syncEl.textContent = data.last_sync_at;
                    }
                }
            } catch (e) {
                // Ignore transient errors
            } finally {
                isPolling = false;
            }
        }

        setInterval(pollStatus, 5000);
    });
</script>
@endpush
