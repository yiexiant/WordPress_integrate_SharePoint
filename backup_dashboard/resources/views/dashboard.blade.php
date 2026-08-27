@extends('layouts.app')

@section('title', 'KPI Management Dashboard')

@section('content')
<div class="space-y-8">
    <!-- Top Hero Section -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                Central Backup Fleet Overview
            </h1>
            <p class="text-sm text-slate-500 mt-1">Real-time status, SharePoint cloud synchronization health, and fleet diagnostics.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('websites.index') }}" class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 shadow-sm transition">
                Bulk Actions
            </a>
            <a href="{{ route('websites.create') }}" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                Register Site
            </a>
        </div>
    </div>

    <!-- 📊 KPI Metrics Grid (Requirement 1 - White & Red Theme) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <!-- KPI 1: Connected Sites -->
        <div class="white-card rounded-xl p-5 border border-slate-200 bg-white">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Connected Sites</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded bg-slate-100 text-slate-700">Fleet</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-slate-900">{{ $totalWebsites }}</span>
                <span class="text-xs text-slate-500">monitored</span>
            </div>
            <div class="mt-3 flex items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-semibold border border-emerald-200">
                    {{ $onlineWebsites }} Online
                </span>
                @if($totalWebsites - $onlineWebsites > 0)
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-red-50 text-red-700 font-semibold border border-red-200">
                        {{ $totalWebsites - $onlineWebsites }} Unreachable
                    </span>
                @endif
            </div>
        </div>

        <!-- KPI 2: Successfully Synced Backups on SharePoint -->
        <div class="white-card rounded-xl p-5 border-l-4 border-l-red-600 border border-slate-200 bg-white">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Synced to SharePoint</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-100">Cloud Storage</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-red-600">{{ $successfulBackups }}</span>
                <span class="text-xs text-slate-600 font-medium">Active in Cloud</span>
            </div>
            <div class="mt-3 text-xs text-slate-500 font-medium">
                Verified on Microsoft Graph Storage
            </div>
        </div>

        <!-- KPI 3: Backups Removed / Deleted on SharePoint (Requirement 5) -->
        <div class="white-card rounded-xl p-5 border border-amber-300 bg-amber-50/50">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-amber-900">Removed from SharePoint</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">Alert</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-amber-800">{{ $removedFromSharePoint }}</span>
                <span class="text-xs text-amber-900 font-medium">Remote Missing</span>
            </div>
            <div class="mt-3 text-xs text-amber-800 font-medium">
                Deleted on remote cloud storage
            </div>
        </div>

        <!-- KPI 4: Total Restores & Active Jobs -->
        <div class="white-card rounded-xl p-5 border border-slate-200 bg-white">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Restores & Active Jobs</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded bg-slate-100 text-slate-700">Operations</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-slate-900">{{ $totalRestores }}</span>
                <span class="text-xs text-slate-500">restores run</span>
            </div>
            <div class="mt-3 flex items-center gap-2 text-xs">
                @if($activeJobsCount > 0)
                    <span class="inline-flex items-center px-2 py-0.5 rounded bg-red-50 text-red-700 font-bold border border-red-200">
                        {{ $activeJobsCount }} Active Job(s)
                    </span>
                @else
                    <span class="text-slate-500 font-medium">All queues idle</span>
                @endif
            </div>
        </div>
    </div>

    <!-- Main Section: Website Fleet & Recent Activity -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Left: Website Fleet Overview Table (2 Cols) -->
        <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-slate-900">
                    Website Fleet Health
                </h2>
                <a href="{{ route('websites.index') }}" class="text-xs font-semibold text-red-600 hover:text-red-700">
                    View all sites &rarr;
                </a>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-50 text-xs uppercase font-semibold text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="px-5 py-3.5">Website</th>
                                <th class="px-4 py-3.5">WP Health</th>
                                <th class="px-4 py-3.5">SharePoint</th>
                                <th class="px-4 py-3.5">Latest Backup</th>
                                <th class="px-4 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($websites as $site)
                                <tr class="hover:bg-slate-50/80 transition">
                                    <!-- Name & URL -->
                                    <td class="px-5 py-4">
                                        <a href="{{ route('websites.show', $site) }}" class="font-bold text-slate-900 hover:text-red-600 transition block">
                                            {{ $site->name }}
                                        </a>
                                        <span class="text-xs text-slate-500 font-mono">{{ $site->clean_url }}</span>
                                    </td>

                                    <!-- WP Health -->
                                    <td class="px-4 py-4">
                                        @if($site->status === 'online')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Online
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200">
                                                Offline
                                            </span>
                                        @endif
                                    </td>

                                    <!-- SharePoint Status -->
                                    <td class="px-4 py-4">
                                        @if($site->sharepoint_status === 'connected')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                                                Connected
                                            </span>
                                        @elseif($site->sharepoint_status === 'error')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200">
                                                Error
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                                                Unconfigured
                                            </span>
                                        @endif
                                    </td>

                                    <!-- Latest Backup -->
                                    <td class="px-4 py-4">
                                        @if($site->latestBackup)
                                            <div class="text-xs text-slate-700 font-mono font-medium">
                                                {{ $site->latestBackup->created_at->diffForHumans() }}
                                            </div>
                                            @if($site->latestBackup->is_removed_from_sharepoint)
                                                <span class="inline-flex items-center text-[11px] font-bold text-amber-700 mt-0.5">
                                                    Removed from SP
                                                </span>
                                            @else
                                                <span class="text-[11px] text-emerald-700 font-medium">
                                                    {{ ucfirst($site->latestBackup->status) }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-xs text-slate-400 italic">Never backed up</span>
                                        @endif
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-4 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" onclick="openBackupConfirmModal('{{ route('websites.trigger-backup', $site) }}', '{{ addslashes($site->name) }}')" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 transition">
                                                Run Backup
                                            </button>
                                            <a href="{{ route('websites.show', $site) }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition">
                                                Details
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-8 text-center text-slate-500">
                                        No WordPress websites registered yet. 
                                        <a href="{{ route('websites.create') }}" class="text-red-600 hover:underline font-semibold block mt-1">Register your first website &rarr;</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right: Recent Activity Stream (1 Col) -->
        <div class="space-y-4">
            <h2 class="text-lg font-bold text-slate-900">
                Live Activity Feed
            </h2>

            <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm space-y-4">
                @forelse($recentActivities as $act)
                    <div class="pb-3 border-b border-slate-100 last:border-0 last:pb-0">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold uppercase tracking-wider
                                @if($act->level === 'success') text-emerald-700
                                @elseif($act->level === 'warning') text-amber-700
                                @elseif($act->level === 'danger') text-red-700
                                @else text-slate-600 @endif">
                                {{ ucfirst(str_replace('_', ' ', $act->action)) }}
                            </span>
                            <span class="text-[11px] text-slate-400 font-mono">{{ $act->created_at->diffForHumans() }}</span>
                        </div>
                        <p class="text-xs font-medium text-slate-800 mt-1 leading-snug">{{ $act->message }}</p>
                        @if($act->website)
                            <span class="text-[11px] text-slate-500 block mt-1 font-medium">{{ $act->website->name }}</span>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-6 text-xs text-slate-400 italic">
                        No activity recorded yet.
                    </div>
                @endforelse
            </div>
        </div>
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
</script>
@endpush
