@extends('layouts.app')

@section('title', $website->name)

@section('content')
@php
    $tabs = [
        'overview' => 'Overview',
        'backups'  => 'Backups ('.$totalBackups.')',
        'restores' => 'Restores',
        'migrate'  => 'Migrate',
    ];
    $backupConfirm = 'Start a full backup of '.$website->name.' to SharePoint now? It runs in the background on the WordPress server; you can leave this page.';
@endphp

<div class="space-y-6">
    {{-- Header: who, how it is doing, and the two things people do most --}}
    <div>
        <a href="{{ route('websites.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← Websites</a>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $website->name }}</h1>
                    <x-health :health="$health" />
                    @if ($website->isMigration())
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Migration target</span>
                    @endif
                </div>
                <a href="{{ $website->url }}" target="_blank" rel="noopener" class="mt-1 inline-block text-sm text-slate-500 hover:text-slate-900">{{ $website->url }} ↗</a>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('websites.test-all', $website) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Check connection</button>
                </form>
                <a href="{{ route('websites.edit', $website) }}" class="btn btn-secondary">Edit</a>
                <form method="POST" action="{{ route('websites.trigger-backup', $website) }}"
                      data-confirm="{{ $backupConfirm }}" data-confirm-title="Back up now?" data-confirm-button="Back up now">
                    @csrf
                    <button type="submit" class="btn btn-primary">Back up now</button>
                </form>
            </div>
        </div>

        @if ($health->level !== 'ok')
            <div @class([
                'mt-4 rounded-lg border px-4 py-3 text-sm',
                'border-red-200 bg-red-50 text-red-900' => $health->level === 'critical',
                'border-amber-200 bg-amber-50 text-amber-900' => $health->level === 'warning',
                'border-slate-200 bg-white text-slate-700' => $health->level === 'unknown',
            ])>
                <span class="font-medium">{{ $health->label }}:</span> {{ $health->reason }}
            </div>
        @endif

        @if ($jobRunning)
            <div id="job-banner" class="mt-4 flex items-center gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="status" aria-live="polite">
                <span class="size-2 animate-pulse rounded-full bg-sky-500" aria-hidden="true"></span>
                <span id="job-text">
                    @if (in_array($website->latestRestore?->status, ['pending', 'downloading', 'extracting', 'restoring_database', 'cleanup'], true))
                        Restore in progress…
                    @else
                        Backup in progress…
                    @endif
                </span>
                <span class="text-sky-700">This page updates by itself.</span>
            </div>
        @endif
    </div>

    {{-- Tabs --}}
    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Website sections">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('websites.show', [$website, 'tab' => $key]) }}" @class([
                '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap',
                'border-slate-900 text-slate-900' => $tab === $key,
                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $tab !== $key,
            ]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    {{-- ===================== OVERVIEW ===================== --}}
    @if ($tab === 'overview')
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card" aria-labelledby="backups-summary">
                <div class="card-header"><h2 id="backups-summary" class="card-title">Backups</h2></div>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">Last successful</dt>
                        <dd class="text-right font-medium text-slate-900" title="{{ $website->last_backup_at?->toDayDateTimeString() }}">{{ $website->last_backup_at?->diffForHumans() ?? 'Never' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">Schedule</dt>
                        <dd class="text-right text-slate-900">
                            @switch($website->backup_frequency)
                                @case('daily') Every day @break
                                @case('weekly') Every week @break
                                @case('monthly') Every month @break
                                @case('once') Once @break
                                @case('manual') Manual only @break
                                @default <span class="text-slate-400">Unknown</span>
                            @endswitch
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">Next backup</dt>
                        <dd class="text-right text-slate-900">{{ $website->next_backup_at?->diffForHumans() ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">Stored on SharePoint</dt>
                        <dd class="text-right text-slate-900">
                            {{ $successfulBackups }}
                            @if ($missingBackups > 0)<span class="text-amber-700">· {{ $missingBackups }} deleted</span>@endif
                        </dd>
                    </div>
                </dl>
                <div class="border-t border-slate-100 px-5 py-3">
                    <a href="{{ route('websites.show', [$website, 'tab' => 'backups']) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">All backups →</a>
                </div>
            </section>

            <section class="card" aria-labelledby="connection-summary">
                <div class="card-header"><h2 id="connection-summary" class="card-title">Connection</h2></div>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">WordPress plugin</dt>
                        <dd>
                            @if ($website->status === 'online')
                                <x-pill level="ok">Reachable</x-pill>
                            @elseif ($website->last_sync_at === null)
                                <x-pill level="unknown">Not checked</x-pill>
                            @else
                                <x-pill level="critical">Unreachable</x-pill>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">SharePoint</dt>
                        <dd>
                            @if ($website->sharepoint_status === 'connected')
                                <x-pill level="ok">Signed in</x-pill>
                            @elseif ($website->sharepoint_status === 'error')
                                <x-pill level="critical">Sign-in failed</x-pill>
                            @else
                                <x-pill level="unknown">Not checked</x-pill>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">SharePoint folder</dt>
                        <dd class="truncate text-right font-mono text-xs text-slate-900">{{ $website->site_folder ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">Plugin version</dt>
                        <dd class="text-right text-slate-900">{{ $website->plugin_version ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-500">Last checked</dt>
                        <dd class="text-right text-slate-900">{{ $website->last_sync_at?->diffForHumans() ?? 'Never' }}</dd>
                    </div>
                </dl>
                @if ($website->last_error)
                    <p class="border-t border-slate-100 px-5 py-3 text-xs break-words text-red-700">{{ $website->last_error }}</p>
                @endif
            </section>

            <section class="card" aria-labelledby="server-summary">
                <div class="card-header"><h2 id="server-summary" class="card-title">Server</h2></div>
                @if (empty($systemInfo))
                    <p class="px-5 py-6 text-sm text-slate-500">No server details yet. Click <strong>Check connection</strong> to load them.</p>
                @else
                    <dl class="divide-y divide-slate-100 text-sm">
                        @foreach ([
                            'WordPress' => $systemInfo['wordpress_version'] ?? null,
                            'PHP' => $systemInfo['php_version'] ?? null,
                            'Database size' => $systemInfo['database_size'] ?? null,
                            'Memory limit' => $systemInfo['memory_limit'] ?? null,
                            'Upload limit' => $systemInfo['upload_max'] ?? null,
                            'Free disk space' => isset($systemInfo['disk']['free_format']) ? $systemInfo['disk']['free_format'].' of '.($systemInfo['disk']['total_format'] ?? '?') : null,
                        ] as $label => $value)
                            <div class="flex justify-between gap-4 px-5 py-3">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="text-right text-slate-900">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="card" aria-labelledby="recent-backups">
                <div class="card-header"><h2 id="recent-backups" class="card-title">Latest backups</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($recentBackups as $backup)
                        <li class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                            <span class="text-slate-900" title="{{ $backup->backup_id }}">{{ $backup->taken_at->format('D j M Y, H:i') }}</span>
                            <x-backup-status :backup="$backup" />
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-slate-500">No backups yet. Click <strong>Back up now</strong> to create the first one.</li>
                    @endforelse
                </ul>
            </section>

            <section class="card" aria-labelledby="site-activity">
                <div class="card-header"><h2 id="site-activity" class="card-title">Recent activity</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($recentActivity as $activity)
                        <li class="px-5 py-3">
                            <p class="text-sm text-slate-700">{{ $activity->message }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">{{ $activity->created_at->diffForHumans() }}</p>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-slate-500">No activity yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    {{-- ===================== BACKUPS ===================== --}}
    @if ($tab === 'backups')
        <section class="card" aria-labelledby="backups-title">
            <div class="card-header">
                <div>
                    <h2 id="backups-title" class="card-title">Backups</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Restoring replaces this website's files and database with the chosen backup. A safety snapshot is taken first.</p>
                </div>
                <form method="POST" action="{{ route('websites.verify-backups', $website) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Check backups on SharePoint</button>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Taken</th>
                            <th>Status</th>
                            <th>Files</th>
                            <th class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($backupLogs as $backup)
                            @php $restorable = $backup->status === 'completed' && ! $backup->is_removed_from_sharepoint; @endphp
                            <tr class="hover:bg-slate-50">
                                <td>
                                    <div class="text-slate-900">{{ $backup->taken_at->format('D j M Y, H:i') }}</div>
                                    <div class="font-mono text-xs text-slate-400">{{ $backup->backup_id }}</div>
                                </td>
                                <td><x-backup-status :backup="$backup" /></td>
                                <td class="text-slate-600">{{ $backup->total_files ? $backup->uploaded_files.' / '.$backup->total_files : '—' }}</td>
                                <td class="text-right">
                                    @if ($restorable)
                                        <form method="POST" action="{{ route('websites.trigger-restore', $website) }}"
                                              data-confirm="Replace the files and database of {{ $website->name }} with the backup from {{ $backup->taken_at->format('D j M Y, H:i') }}?&#10;&#10;A safety snapshot is taken first and used automatically if the restore fails. Changes made since this backup will be lost."
                                              data-confirm-title="Restore this backup?" data-confirm-button="Restore website" data-confirm-danger>
                                            @csrf
                                            <input type="hidden" name="backup_id" value="{{ $backup->backup_id }}">
                                            <button type="submit" class="btn btn-secondary btn-sm">Restore</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">Not restorable</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-12 text-center text-sm text-slate-500">No backups yet. Click <strong>Back up now</strong> to create the first one.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($backupLogs->hasPages())
                <div class="border-t border-slate-200 px-5 py-3">{{ $backupLogs->links() }}</div>
            @endif
        </section>
    @endif

    {{-- ===================== RESTORES ===================== --}}
    @if ($tab === 'restores')
        <section class="card" aria-labelledby="restores-title">
            <div class="card-header">
                <h2 id="restores-title" class="card-title">Restore history</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Started</th>
                            <th>From backup</th>
                            <th>Result</th>
                            <th>Took</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($restoreLogs as $restore)
                            <tr>
                                <td class="text-slate-900">{{ ($restore->started_at ?? $restore->created_at)->format('D j M Y, H:i') }}</td>
                                <td class="font-mono text-xs text-slate-600">{{ $restore->backup_id }}</td>
                                <td>
                                    <x-restore-status :restore="$restore" />
                                    @if ($restore->status === 'failed' && $restore->error_message)
                                        <p class="mt-1 max-w-md text-xs text-red-700">{{ $restore->error_message }}</p>
                                    @endif
                                </td>
                                <td class="text-slate-600">{{ $restore->formatted_duration }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-12 text-center text-sm text-slate-500">This website has never been restored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($restoreLogs->hasPages())
                <div class="border-t border-slate-200 px-5 py-3">{{ $restoreLogs->links() }}</div>
            @endif
        </section>
    @endif

    {{-- ===================== MIGRATE ===================== --}}
    @if ($tab === 'migrate')
        <p class="max-w-3xl text-sm text-slate-600">
            Copy another website onto <strong>{{ $website->name }}</strong> in two steps: restore the other website's backup here,
            then replace its old address with this website's address in the database.
        </p>

        <section class="card" aria-labelledby="migrate-step-1">
            <div class="card-header">
                <div>
                    <h2 id="migrate-step-1" class="card-title">Step 1 · Restore another website's backup here</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Both websites must use the same SharePoint library and root folder in their plugin settings.</p>
                </div>
            </div>
            <div class="px-5 py-4">
                @if ($migrationSources->isEmpty())
                    <p class="text-sm text-slate-500">No other website has completed backups available yet. The source website needs plugin 1.4.0 or newer and at least one successful backup.</p>
                @else
                    <form method="POST" action="{{ route('websites.trigger-restore', $website) }}" class="flex flex-wrap items-end gap-3"
                          data-confirm="Replace the files and database of {{ $website->name }} with the selected backup from another website?&#10;&#10;A safety snapshot is taken first. Everything currently on {{ $website->name }} will be overwritten."
                          data-confirm-title="Restore from another website?" data-confirm-button="Restore website" data-confirm-danger>
                        @csrf
                        <input type="hidden" name="source_website_id" id="migration-source-id">
                        <input type="hidden" name="backup_id" id="migration-backup-id">
                        <div class="min-w-64 flex-1">
                            <label for="migration-backup" class="label">Backup to copy</label>
                            <select id="migration-backup" required class="input"
                                    onchange="const o = this.selectedOptions[0]; document.getElementById('migration-source-id').value = o.dataset.source || ''; document.getElementById('migration-backup-id').value = o.dataset.backup || '';">
                                <option value="">Choose a backup…</option>
                                @foreach ($migrationSources as $source)
                                    <optgroup label="{{ $source->name }}">
                                        @foreach ($source->backupLogs as $sourceBackup)
                                            <option value="{{ $source->id }}:{{ $sourceBackup->backup_id }}" data-source="{{ $source->id }}" data-backup="{{ $sourceBackup->backup_id }}">
                                                {{ $sourceBackup->taken_at->format('D j M Y, H:i') }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Restore into {{ $website->name }}</button>
                    </form>
                @endif
            </div>
        </section>

        <section class="card" aria-labelledby="migrate-step-2">
            <div class="card-header">
                <div>
                    <h2 id="migrate-step-2" class="card-title">Step 2 · Update the website address in the database</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Replaces every occurrence of the old address, including inside serialized settings. Preview first: it changes nothing.</p>
                </div>
            </div>

            @if (session('url_update_result'))
                @php $result = session('url_update_result'); @endphp
                <div class="mx-5 mt-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    <p class="font-medium text-slate-900">
                        {{ ! empty($result['dry_run']) ? 'Preview: nothing was changed' : 'Database updated' }}
                    </p>
                    <p class="mt-1 text-slate-600">
                        {{ $result['replacements_count'] ?? 0 }} {{ Str::plural('replacement', $result['replacements_count'] ?? 0) }}
                        in {{ $result['rows_updated'] ?? 0 }} {{ Str::plural('row', $result['rows_updated'] ?? 0) }}
                        across {{ $result['tables_updated'] ?? 0 }} of {{ $result['tables_scanned'] ?? 0 }} tables
                        ({{ $result['duration_seconds'] ?? 0 }} s).
                    </p>
                    @if (! empty($result['tables']))
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($result['tables'] as $table)
                                <li class="rounded bg-white px-2 py-0.5 font-mono text-xs text-slate-600 ring-1 ring-slate-200">{{ $table['table'] }} · {{ $table['rows_updated'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('websites.update-url', $website) }}" class="space-y-4 px-5 py-4"
                  data-confirm="Permanently replace the old address with the new one in the database of {{ $website->name }}? Run a preview first if you have not."
                  data-confirm-title="Update the database?" data-confirm-button="Update database" data-confirm-danger>
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="old_url" class="label">Old address (the copied website)</label>
                        <input type="url" name="old_url" id="old_url" value="{{ old('old_url') }}" required placeholder="https://old-site.com" class="input">
                        @if ($otherWebsites->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($otherWebsites as $other)
                                    <button type="button" onclick="document.getElementById('old_url').value = @js($other->url)" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-200">{{ $other->name }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <label for="new_url" class="label">New address (this website)</label>
                        <input type="url" name="new_url" id="new_url" value="{{ old('new_url', $website->url) }}" required class="input">
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="submit" name="dry_run" value="1" data-skip-confirm class="btn btn-secondary">Preview changes</button>
                    <button type="submit" name="dry_run" value="0" class="btn btn-danger">Apply changes</button>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection

@if ($jobRunning)
    @push('scripts')
    <script>
        // Live progress only while a backup/restore runs; reload once it finishes.
        (function () {
            const url = @js(route('websites.poll-status', $website));
            const text = document.getElementById('job-text');
            const active = ['queued', 'uploading', 'pending', 'downloading', 'extracting', 'restoring_database', 'cleanup'];
            const phases = { pending: 'Starting', downloading: 'Downloading backup', extracting: 'Restoring files', restoring_database: 'Restoring database', cleanup: 'Finishing' };
            let busy = false;

            setInterval(async () => {
                if (busy || document.hidden) return;
                busy = true;
                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    const backup = data.latest_backup;
                    const restore = data.latest_restore;

                    if (restore && active.includes(restore.status)) {
                        text.textContent = 'Restore in progress: ' + (phases[restore.status] || restore.status) + '…';
                    } else if (backup && active.includes(backup.status)) {
                        text.textContent = backup.total_files
                            ? `Backup uploading: ${backup.uploaded_files} of ${backup.total_files} files…`
                            : 'Backup is being prepared…';
                    } else {
                        window.location.reload();
                    }
                } catch (e) {
                    // Network hiccup: try again on the next tick.
                } finally {
                    busy = false;
                }
            }, 8000);
        })();
    </script>
    @endpush
@endif
