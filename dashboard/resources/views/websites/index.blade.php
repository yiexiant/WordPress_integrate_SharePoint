@extends('layouts.app')

@section('title', 'Websites')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Websites</h1>
            <p class="mt-1 text-sm text-slate-500">Every WordPress site connected to this dashboard.</p>
        </div>
        <a href="{{ route('websites.create') }}" class="btn btn-primary">Add website</a>
    </div>

    <div class="card">
        {{-- Filters (GET) --}}
        <form method="GET" action="{{ route('websites.index') }}" class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-5 py-4">
            <label for="search" class="sr-only">Search websites</label>
            <input id="search" type="search" name="search" value="{{ request('search') }}" placeholder="Search by name or address…" class="input mt-0 max-w-xs">

            <div class="flex rounded-lg border border-slate-300 p-0.5 text-sm" role="group" aria-label="Filter by status">
                @foreach (['all' => 'All', 'attention' => 'Needs attention ('.$attentionCount.')', 'healthy' => 'Healthy'] as $value => $label)
                    <button type="submit" name="filter" value="{{ $value }}" @class([
                        'rounded-md px-3 py-1.5 font-medium',
                        'bg-slate-900 text-white' => $filter === $value,
                        'text-slate-600 hover:bg-slate-100' => $filter !== $value,
                    ]) @if($filter === $value) aria-pressed="true" @endif>{{ $label }}</button>
                @endforeach
            </div>

            @if (request('search') || $filter !== 'all')
                <a href="{{ route('websites.index') }}" class="text-sm text-slate-500 hover:text-slate-900">Clear</a>
            @endif
        </form>

        {{-- Bulk backup (POST); checkboxes below belong to this form via form="bulk-form". --}}
        <form id="bulk-form" method="POST" action="{{ route('websites.bulk-trigger-backup') }}"
              data-confirm="Start a SharePoint backup on every selected website now? Each runs in the background on its own server."
              data-confirm-title="Back up selected websites?" data-confirm-button="Back up selected"
              class="flex flex-wrap items-center justify-between gap-3 bg-slate-50 px-5 py-2.5">
            @csrf
            <span class="text-sm text-slate-500" data-selection-count="sites"></span>
            <button type="submit" class="btn btn-secondary btn-sm" data-requires-selection="sites" disabled>Back up selected</button>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-10"><input type="checkbox" data-select-all="sites" aria-label="Select all websites" class="size-4 rounded border-slate-300"></th>
                        <th>Website</th>
                        <th>Status</th>
                        <th>Last successful backup</th>
                        <th>Schedule</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($websites as $site)
                        @php $health = $site->healthState; @endphp
                        <tr class="hover:bg-slate-50">
                            <td>
                                <input type="checkbox" name="website_ids[]" value="{{ $site->id }}" form="bulk-form" data-select="sites"
                                       aria-label="Select {{ $site->name }}" class="size-4 rounded border-slate-300">
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('websites.show', $site) }}" class="font-medium text-slate-900 hover:underline">{{ $site->name }}</a>
                                    @if ($site->isMigration())
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Migration target</span>
                                    @endif
                                </div>
                                <div class="text-xs text-slate-500">{{ $site->url }}</div>
                            </td>
                            <td>
                                <x-health :health="$health" />
                                @if ($health->needsAttention())
                                    <div class="mt-1 max-w-xs text-xs text-slate-500">{{ Str::limit($health->reason, 90) }}</div>
                                @endif
                            </td>
                            <td class="text-slate-600">{{ $site->last_backup_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="text-slate-600">
                                @if ($site->backup_frequency && $site->backup_frequency !== 'manual')
                                    {{ ucfirst($site->backup_frequency) }}
                                    @if ($site->next_backup_at)<div class="text-xs text-slate-400">next {{ $site->next_backup_at->diffForHumans() }}</div>@endif
                                @else
                                    <span class="text-slate-400">{{ $site->backup_frequency === 'manual' ? 'Manual only' : 'Unknown' }}</span>
                                @endif
                            </td>
                            <td class="text-right"><a href="{{ route('websites.show', $site) }}" class="btn btn-secondary btn-sm">Open</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-sm text-slate-500">
                                @if (request('search') || $filter !== 'all')
                                    No websites match. <a href="{{ route('websites.index') }}" class="link">Show all</a>
                                @else
                                    No websites yet. <a href="{{ route('websites.create') }}" class="link">Add your first website</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($websites->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $websites->links() }}</div>
        @endif
    </div>
</div>
@endsection
