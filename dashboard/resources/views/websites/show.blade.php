@extends('layouts.app')

@section('title', $website->name . ' - Site Details')

@section('content')
<div class="space-y-8">
    <!-- Top Site Header & Status Bar -->
    <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">{{ $website->name }}</h1>
                
                <!-- Purpose Badge -->
                @if($website->isMigration())
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200">
                        Migration Target
                    </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                        Scheduled Backup
                    </span>
                @endif

                <!-- WP Status Badge -->
                @if($website->status === 'online')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                        WordPress Online
                    </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200">
                        WordPress Offline
                    </span>
                @endif

                <!-- SharePoint Status Badge -->
                @if($website->sharepoint_status === 'connected')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                        SharePoint Connected
                    </span>
                @elseif($website->sharepoint_status === 'error')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200">
                        SharePoint Auth Error
                    </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                        SharePoint Unchecked
                    </span>
                @endif

                <!-- Backup freshness (plugin 1.4.0+) -->
                @if($website->backup_overdue)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-600 text-white" title="Newest successful backup: {{ $website->last_backup_at?->diffForHumans() ?? 'never' }}">
                        Backup Overdue
                    </span>
                @elseif($website->backup_frequency && $website->backup_frequency !== 'manual')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                        {{ ucfirst($website->backup_frequency) }} backups{{ $website->next_backup_at ? ' · next '.$website->next_backup_at->diffForHumans() : '' }}
                    </span>
                @elseif($website->backup_frequency === 'manual')
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200" title="Automatic SharePoint backups are turned off in WordPress.">
                        No automatic schedule
                    </span>
                @endif
            </div>
            @if($website->site_folder || $website->last_error)
                <div class="mt-2 text-xs text-slate-500 space-y-0.5">
                    @if($website->site_folder)
                        <div>SharePoint site folder: <code class="font-mono text-slate-700">{{ $website->site_folder }}</code>@if($website->plugin_version) · plugin v{{ $website->plugin_version }}@endif</div>
                    @endif
                    @if($website->last_error)
                        <div class="text-red-700">Last sync error: {{ $website->last_error }}</div>
                    @endif
                </div>
            @endif

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

    <!-- Connection Diagnostics & Action Toolbar -->
    <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">
                    Connection Diagnostics & Remote Verification
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">Test individual connections or perform deep SharePoint file scans to detect deleted backups.</p>
            </div>

            <!-- Action Buttons Group -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Test WordPress Connection -->
                <form method="POST" action="{{ route('websites.test-wp', $website) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-3.5 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 transition shadow-xs">
                        Test WP Connection
                    </button>
                </form>

                <!-- Verify Remote Backups -->
                <form method="POST" action="{{ route('websites.verify-backups', $website) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-3.5 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 transition shadow-xs">
                        Verify SharePoint Files
                    </button>
                </form>

                <!-- Trigger Single Backup Now -->
                <button type="button" onclick="openBackupConfirmModal('{{ route('websites.trigger-backup', $website) }}', '{{ addslashes($website->name) }}')" class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                    Run Backup Now
                </button>
            </div>
        </div>
    </div>

    <!-- Migration Assistant & Database URL Update Tool (White & Red Design) -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-200 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white">
            <div class="space-y-1">
                <h2 class="text-base font-extrabold tracking-tight text-slate-900">
                    Migration Assistant & Database URL Search and Replace
                </h2>
                <p class="text-xs text-slate-500">
                    Migrate an existing WordPress site backup to this instance and safely update database URLs with serialization integrity.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" onclick="toggleMigrationGuide()" class="px-3.5 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 transition shadow-xs">
                    Toggle Migration Tutorial
                </button>
            </div>
        </div>

        <!-- Collapsible Interactive Migration Tutorial (Clean White & Red) -->
        <div id="migration-workflow-guide" class="{{ $website->isMigration() ? '' : 'hidden' }} p-6 bg-slate-50 border-b border-slate-200 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">
                End-to-End Migration Workflow
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 text-xs">
                <!-- Step 1 -->
                <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-slate-900">1. Pair & Verify</span>
                        @if($website->status === 'online' && $website->sharepoint_status === 'connected')
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-800 border border-slate-200">Ready</span>
                        @else
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200">Pending</span>
                        @endif
                    </div>
                    <p class="text-[11px] text-slate-500 leading-relaxed">
                        Plugin installed, Azure credentials configured, and REST API authenticated.
                    </p>
                </div>

                <!-- Step 2 -->
                <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-slate-900">2. Restore Backup</span>
                        @if($website->latestRestore && $website->latestRestore->status === 'completed')
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-800 border border-slate-200">Restored</span>
                        @else
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600">Action</span>
                        @endif
                    </div>
                    <p class="text-[11px] text-slate-500 leading-relaxed">
                        Choose a backup below and click <strong>Restore Site</strong> to unpack files & import SQL.
                    </p>
                </div>

                <!-- Step 3 -->
                <div class="bg-white rounded-xl p-4 border-2 border-red-600 shadow-xs space-y-1.5 bg-red-50/20">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-red-900">3. Update Database URLs</span>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-red-100 text-red-800 border border-red-200">Current</span>
                    </div>
                    <p class="text-[11px] text-slate-600 leading-relaxed">
                        Use the tool below to replace old domain URLs with <code class="text-red-700 font-mono">{{ $website->url }}</code>.
                    </p>
                </div>

                <!-- Step 4 -->
                <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-slate-900">4. Verify & Flush</span>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600">Final</span>
                    </div>
                    <p class="text-[11px] text-slate-500 leading-relaxed">
                        Caches and rewrite rules are flushed automatically. Log in to WordPress Admin to verify.
                    </p>
                </div>
            </div>
        </div>

        <!-- Cross-site restore: pull another website's SharePoint backup into this one -->
        <div class="p-6 sm:p-8 border-b border-slate-200 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-900">Restore a backup from another website</h4>
            <p class="text-xs text-slate-600 leading-relaxed">
                Each website keeps its backups in its own SharePoint site folder. To migrate, pick a completed backup of the source website.
                This website must use the same SharePoint library and root folder. Run the URL update below afterwards.
            </p>
            @if($migrationSources->isEmpty())
                <p class="text-xs text-slate-500 italic">No other website has reported a SharePoint site folder with completed backups yet (plugin 1.4.0+ and a connection test are required).</p>
            @else
                <form method="POST" action="{{ route('websites.trigger-restore', $website) }}" class="flex flex-col sm:flex-row gap-3 sm:items-end"
                      onsubmit="return confirm('Overwrite {{ e($website->name) }} with the selected backup from another website? A rollback snapshot is taken first.');">
                    @csrf
                    <input type="hidden" name="source_website_id" id="migration-source-id" value="">
                    <input type="hidden" name="backup_id" id="migration-backup-id" value="">
                    <label class="flex-1 text-xs font-semibold text-slate-700">
                        Source backup
                        <select required class="mt-1 w-full bg-slate-50 border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono"
                                onchange="const o=this.selectedOptions[0]; document.getElementById('migration-source-id').value=o.dataset.source||''; document.getElementById('migration-backup-id').value=o.dataset.backup||'';">
                            <option value="">Choose a backup…</option>
                            @foreach($migrationSources as $source)
                                <optgroup label="{{ $source->name }} ({{ $source->site_folder }})">
                                    @foreach($source->backupLogs as $sourceBackup)
                                        <option value="{{ $source->id }}:{{ $sourceBackup->backup_id }}" data-source="{{ $source->id }}" data-backup="{{ $sourceBackup->backup_id }}">{{ $sourceBackup->backup_id }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                        Restore into {{ $website->name }}
                    </button>
                </form>
            @endif
        </div>

        <!-- Database URL Update Form Section -->
        <div class="p-6 sm:p-8 space-y-6">
            @if(session('url_update_result'))
                @php $res = session('url_update_result'); @endphp
                <div class="rounded-xl border border-slate-300 p-5 space-y-3 bg-white shadow-sm border-l-4 border-l-red-600">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-900">
                            {{ !empty($res['dry_run']) ? 'Dry Run (Preview) Summary' : 'Database URL Update Execution Report' }}
                        </h4>
                        <span class="text-[11px] font-mono font-semibold text-slate-500">
                            Execution: {{ $res['duration_seconds'] ?? 0 }}s
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                        <div class="bg-slate-50 rounded-lg p-3 border border-slate-200">
                            <span class="text-[10px] uppercase font-bold text-slate-500 block">Replacements</span>
                            <span class="text-lg font-extrabold font-mono mt-0.5 block text-red-600">{{ $res['replacements_count'] ?? 0 }}</span>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-3 border border-slate-200">
                            <span class="text-[10px] uppercase font-bold text-slate-500 block">Rows Updated</span>
                            <span class="text-lg font-extrabold font-mono mt-0.5 block text-slate-900">{{ $res['rows_updated'] ?? 0 }}</span>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-3 border border-slate-200">
                            <span class="text-[10px] uppercase font-bold text-slate-500 block">Tables Scanned</span>
                            <span class="text-lg font-extrabold font-mono mt-0.5 block text-slate-900">{{ $res['tables_scanned'] ?? 0 }}</span>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-3 border border-slate-200">
                            <span class="text-[10px] uppercase font-bold text-slate-500 block">Tables Affected</span>
                            <span class="text-lg font-extrabold font-mono mt-0.5 block text-slate-900">{{ $res['tables_updated'] ?? 0 }}</span>
                        </div>
                    </div>

                    @if(!empty($res['tables']) && count($res['tables']) > 0)
                        <div class="mt-3">
                            <span class="text-[11px] font-bold block mb-1.5 text-slate-700">Affected Tables Breakdown:</span>
                            <div class="flex flex-wrap gap-2">
                                @foreach($res['tables'] as $t)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-slate-50 text-xs font-mono border border-slate-200 text-slate-800">
                                        <strong>{{ $t['table'] }}</strong>
                                        <span class="text-slate-500">({{ $t['rows_updated'] }} rows, {{ $t['replacements'] }} matches)</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <form id="url-update-form" method="POST" action="{{ route('websites.update-url', $website) }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Old URL -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label for="old_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Old Website URL (Source) <span class="text-red-600">*</span>
                            </label>
                            @if(isset($otherWebsites) && $otherWebsites->count() > 0)
                                <span class="text-[11px] text-slate-400">Quick fill from dashboard:</span>
                            @endif
                        </div>
                        <input type="url" name="old_url" id="old_url" value="{{ old('old_url') }}" required placeholder="https://old-domain.com" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 font-mono focus:outline-none focus:border-red-600 focus:bg-white">
                        
                        @if(isset($otherWebsites) && $otherWebsites->count() > 0)
                            <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                <span class="text-[11px] text-slate-400">Suggestions:</span>
                                @foreach($otherWebsites as $other)
                                    <button type="button" onclick="setOldUrl('{{ $other->url }}')" class="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-200 transition">
                                        {{ $other->name }} ({{ parse_url($other->url, PHP_URL_HOST) ?? $other->url }})
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- New URL -->
                    <div class="space-y-1.5">
                        <label for="new_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                            New Website URL (Target) <span class="text-red-600">*</span>
                        </label>
                        <input type="url" name="new_url" id="new_url" value="{{ old('new_url', $website->url) }}" required class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 font-mono focus:outline-none focus:border-red-600 focus:bg-white">
                        <p class="text-[11px] text-slate-500">Defaults to this WordPress site's configured URL.</p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-3 border-t border-slate-100">
                    <!-- Dry Run Toggle -->
                    <label class="flex items-center gap-2 cursor-pointer select-none text-xs text-slate-700">
                        <input type="checkbox" name="dry_run" id="dry_run" value="1" checked class="rounded text-red-600 focus:ring-red-500 w-4 h-4">
                        <span><strong>Dry Run Preview:</strong> Test and count matching database rows without applying changes</span>
                    </label>

                    <!-- Action Buttons -->
                    <div class="flex items-center gap-2.5">
                        <button type="button" onclick="triggerUrlUpdate(true)" class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-800 bg-white hover:bg-slate-50 border border-slate-300 transition shadow-xs">
                            Run Dry Run (Preview)
                        </button>
                        <button type="button" onclick="triggerUrlUpdate(false)" class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                            Apply Live URL Update
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Website System Info Grid -->
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

        <!-- Disk Space Usage Bar -->
        @if(isset($systemInfo['disk']['free_format']))
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex items-center justify-between text-xs text-slate-700">
                <span class="font-medium">
                    Server Disk Space: <strong class="text-slate-900">{{ $systemInfo['disk']['free_format'] }} Free</strong> of {{ $systemInfo['disk']['total_format'] ?? 'Total' }}
                </span>
                <span class="text-slate-400 font-mono text-[11px]">Server Storage</span>
            </div>
        @endif
    </div>

    <!-- Backup History & SharePoint Sync State -->
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

                                <!-- SharePoint Cloud Status -->
                                <td class="px-4 py-4">
                                    @if($backup->is_removed_from_sharepoint || $backup->status === 'missing')
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200" title="This backup file is no longer present on SharePoint storage.">
                                            Removed from SharePoint
                                        </span>
                                    @elseif($backup->status === 'completed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                                            Synced to SharePoint
                                        </span>
                                    @elseif($backup->status === 'uploading' || $backup->status === 'queued')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                                            Uploading ({{ $backup->uploaded_files }}/{{ $backup->total_files }})
                                        </span>
                                    @elseif($backup->status === 'failed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200">
                                            Upload Failed
                                        </span>
                                    @elseif($backup->status === 'incomplete')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200" title="The SharePoint folder has fewer files than were uploaded. This backup cannot be fully restored.">
                                            Incomplete on SharePoint
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
                                    <span class="text-[11px] text-slate-400 block">({{ $backup->created_at->diffForHumans() }}</span>
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

    <!-- Restoration History & Restoration Duration -->
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-slate-900">
                Restoration History & Performance Metrics
            </h2>
            <span class="text-xs font-semibold text-slate-500">Tracks restoration duration and rollback status</span>
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
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                                            Restored Successfully
                                        </span>
                                    @elseif($restore->status === 'failed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200" title="{{ $restore->error_message }}">
                                            Rollback Triggered
                                        </span>
                                        @if($restore->error_message)
                                            <div class="mt-1 text-[11px] text-red-700 max-w-xs">{{ $restore->error_message }}</div>
                                        @endif
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-300">
                                            {{ ucfirst(str_replace('_', ' ', $restore->status)) }}
                                        </span>
                                    @endif
                                </td>

                                <!-- Restoration Duration Metric -->
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

<!-- Modal: Confirm Database URL Update (Live Mode) -->
<div id="url-confirm-modal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 border border-slate-200 shadow-2xl space-y-5">
        <div>
            <h3 class="text-base font-bold text-red-700">Confirm Live Database URL Update</h3>
            <span class="text-xs text-slate-500">Serialized-safe Search & Replace</span>
        </div>

        <div class="space-y-2 text-xs text-slate-700 leading-relaxed">
            <p>
                You are about to execute a live database replacement on <strong class="text-slate-900">{{ $website->name }}</strong>:
            </p>
            <div class="bg-slate-50 rounded-lg p-3 border border-slate-200 font-mono text-[11px] space-y-1">
                <div>Old URL: <span id="confirm-old-url" class="text-red-600 font-bold"></span></div>
                <div>New URL: <span id="confirm-new-url" class="text-slate-900 font-bold"></span></div>
            </div>
            <p class="text-[11px] text-slate-500">
                All occurrences in posts, options (including <code class="text-slate-800 font-mono">siteurl</code> and <code class="text-slate-800 font-mono">home</code>), postmeta, and taxonomies will be updated.
            </p>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <button type="button" onclick="closeUrlConfirmModal()" class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 transition">
                Cancel
            </button>
            <button type="button" onclick="submitLiveUrlUpdate()" class="px-4 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                Yes, Update Database
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function toggleMigrationGuide() {
        const guide = document.getElementById('migration-workflow-guide');
        guide.classList.toggle('hidden');
    }

    function setOldUrl(url) {
        document.getElementById('old_url').value = url;
    }

    function triggerUrlUpdate(isDryRun) {
        const form = document.getElementById('url-update-form');
        const oldUrl = document.getElementById('old_url').value.trim();
        const newUrl = document.getElementById('new_url').value.trim();
        const dryRunCheckbox = document.getElementById('dry_run');

        if (!oldUrl || !newUrl) {
            alert('Please enter both Old Website URL and New Website URL.');
            return;
        }

        if (isDryRun) {
            dryRunCheckbox.checked = true;
            form.submit();
        } else {
            dryRunCheckbox.checked = false;
            document.getElementById('confirm-old-url').textContent = oldUrl;
            document.getElementById('confirm-new-url').textContent = newUrl;
            const modal = document.getElementById('url-confirm-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    }

    function closeUrlConfirmModal() {
        const modal = document.getElementById('url-confirm-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function submitLiveUrlUpdate() {
        document.getElementById('dry_run').checked = false;
        document.getElementById('url-update-form').submit();
    }

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
