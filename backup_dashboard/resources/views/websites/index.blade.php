@extends('layouts.app')

@section('title', 'Connected Websites')

@section('content')
<div class="space-y-6">
    <!-- Header Area -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                Connected WordPress Fleet
            </h1>
            <p class="text-sm text-slate-500 mt-1">Manage all paired WordPress websites, trigger individual or bulk backups, and monitor cloud sync.</p>
        </div>
        <a href="{{ route('websites.create') }}" class="inline-flex items-center px-4 py-2.5 rounded-lg text-sm font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
            Register New Website
        </a>
    </div>

    <!-- Filter & Bulk Actions Bar -->
    <form id="bulk-form" method="POST" action="{{ route('websites.bulk-trigger-backup') }}" class="space-y-4" onsubmit="return confirm('Are you sure you want to trigger backups for all selected websites?');">
        @csrf

        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <!-- Left: Search & Filter -->
            <div class="flex flex-wrap items-center gap-3 flex-1">
                <div class="relative flex-1 min-w-[240px]">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search websites by name or URL..." class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-red-600 focus:bg-white" onkeydown="if(event.key==='Enter'){event.preventDefault(); window.location='{{ route('websites.index') }}?search='+encodeURIComponent(this.value);}">
                </div>

                <select name="status_filter" onchange="window.location='{{ route('websites.index') }}?status='+this.value;" class="bg-slate-50 border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-red-600 focus:bg-white">
                    <option value="">All Statuses</option>
                    <option value="online" {{ request('status') === 'online' ? 'selected' : '' }}>Online</option>
                    <option value="offline" {{ request('status') === 'offline' ? 'selected' : '' }}>Offline</option>
                </select>
            </div>

            <!-- Right: ⚡ Bulk Trigger Action (Requirement 3) -->
            <div class="flex items-center gap-3">
                <span id="selected-counter" class="text-xs font-semibold text-slate-600">0 selected</span>
                <button type="submit" id="bulk-trigger-btn" disabled class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm transition">
                    Bulk Trigger Backup
                </button>
            </div>
        </div>

        <!-- Websites Table -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs uppercase font-semibold text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-4 w-12 text-center">
                                <input type="checkbox" id="select-all-checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500 h-4 w-4 cursor-pointer">
                            </th>
                            <th class="px-5 py-4">Website Details</th>
                            <th class="px-4 py-4">WP Connection</th>
                            <th class="px-4 py-4">SharePoint</th>
                            <th class="px-4 py-4">Backups</th>
                            <th class="px-4 py-4">Latest Backup</th>
                            <th class="px-5 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($websites as $site)
                            <tr class="hover:bg-slate-50/80 transition">
                                <!-- Checkbox -->
                                <td class="px-4 py-4 text-center">
                                    <input type="checkbox" name="website_ids[]" value="{{ $site->id }}" class="site-checkbox rounded border-slate-300 text-red-600 focus:ring-red-500 h-4 w-4 cursor-pointer">
                                </td>

                                <!-- Name & URL -->
                                <td class="px-5 py-4">
                                    <a href="{{ route('websites.show', $site) }}" class="font-bold text-slate-900 hover:text-red-600 text-base transition block">
                                        {{ $site->name }}
                                    </a>
                                    <a href="{{ $site->url }}" target="_blank" class="text-xs text-slate-500 hover:text-slate-700 font-mono inline-block mt-0.5">
                                        {{ $site->clean_url }}
                                    </a>
                                </td>

                                <!-- WP Connection Health -->
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

                                <!-- Total Backups Count -->
                                <td class="px-4 py-4">
                                    <span class="px-2.5 py-1 rounded text-xs font-mono font-bold bg-slate-100 text-slate-800 border border-slate-200">
                                        {{ $site->backup_logs_count }}
                                    </span>
                                </td>

                                <!-- Latest Backup Info -->
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
                                <td class="px-5 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('websites.show', $site) }}" class="px-3 py-1.5 rounded-lg text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition">
                                            View Details &rarr;
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center text-slate-500">
                                    <span class="text-base font-semibold text-slate-700 block">No WordPress Websites Found</span>
                                    <p class="text-xs text-slate-500 mt-1">Get started by pairing your first WordPress installation with this dashboard.</p>
                                    <a href="{{ route('websites.create') }}" class="inline-block px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 mt-4 shadow-sm transition">
                                        Register First Website
                                    </a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($websites->hasPages())
                <div class="p-4 border-t border-slate-200 bg-slate-50">
                    {{ $websites->links() }}
                </div>
            @endif
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const selectAll = document.getElementById('select-all-checkbox');
        const checkboxes = document.querySelectorAll('.site-checkbox');
        const bulkBtn = document.getElementById('bulk-trigger-btn');
        const counter = document.getElementById('selected-counter');

        function updateSelection() {
            const checkedCount = document.querySelectorAll('.site-checkbox:checked').length;
            counter.textContent = `${checkedCount} selected`;
            bulkBtn.disabled = checkedCount === 0;

            if (selectAll) {
                selectAll.checked = checkedCount > 0 && checkedCount === checkboxes.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                updateSelection();
            });
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateSelection);
        });
    });
</script>
@endpush
