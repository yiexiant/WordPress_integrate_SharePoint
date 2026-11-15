@extends('layouts.app')

@section('title', 'Overview')

@section('content')
<div class="space-y-8">
    {{-- One-sentence answer: is everything OK? --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
                @if ($websites->isEmpty())
                    Welcome! Add your first website
                @elseif ($attention->isEmpty())
                    All {{ $websites->count() }} {{ Str::plural('website', $websites->count()) }} look good
                @else
                    {{ $attention->count() }} of {{ $websites->count() }} {{ Str::plural('website', $websites->count()) }} {{ $attention->count() === 1 ? 'needs' : 'need' }} attention
                @endif
            </h1>
            <p class="mt-1 text-sm text-slate-500">Status refreshes automatically every 5 minutes while the scheduler is running.</p>
        </div>
    </div>

    @if ($websites->isEmpty())
        <div class="card px-6 py-12 text-center">
            <p class="text-base font-medium text-slate-900">No websites connected yet</p>
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">Install the SharePoint Backup plugin on a WordPress site, copy its API key, and add the site here to start monitoring its backups.</p>
            <a href="{{ route('websites.create') }}" class="btn btn-primary mt-6">Add website</a>
        </div>
    @else
        <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat label="Websites" :value="$websites->count()" />
            <x-stat label="Healthy" :value="$healthyCount" tone="ok" />
            <x-stat label="Need attention" :value="$attention->count()" :tone="$attention->isEmpty() ? 'default' : 'critical'" />
            <x-stat label="Backed up in last 24 h" :value="$backedUpToday.' / '.$websites->count()">
                @if ($runningJobs > 0)
                    <x-slot:hint>{{ $runningJobs }} backup/restore {{ Str::plural('job', $runningJobs) }} running now</x-slot:hint>
                @endif
            </x-stat>
        </dl>

        @if ($attention->isNotEmpty())
            <section class="card" aria-labelledby="attention-title">
                <div class="card-header">
                    <h2 id="attention-title" class="card-title">Needs attention</h2>
                </div>
                <ul class="divide-y divide-slate-100">
                    @foreach ($attention as $site)
                        <li class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('websites.show', $site) }}" class="font-medium text-slate-900 hover:underline">{{ $site->name }}</a>
                                    <x-health :health="$site->healthState" />
                                </div>
                                <p class="mt-1 text-sm text-slate-600">{{ $site->healthState->reason }}</p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                @if ($site->status !== 'online' || $site->sharepoint_status === 'error')
                                    <form method="POST" action="{{ route('websites.test-all', $site) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Check connection</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('websites.trigger-backup', $site) }}"
                                          data-confirm="Start a full backup of {{ $site->name }} to SharePoint now? It runs in the background on the WordPress server."
                                          data-confirm-title="Back up now?" data-confirm-button="Back up now">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Back up now</button>
                                    </form>
                                @endif
                                <a href="{{ route('websites.show', $site) }}" class="btn btn-secondary btn-sm">Open</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="grid gap-8 lg:grid-cols-3">
            <section class="card lg:col-span-2" aria-labelledby="fleet-title">
                <div class="card-header">
                    <h2 id="fleet-title" class="card-title">All websites</h2>
                    <a href="{{ route('websites.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">Manage websites →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Website</th>
                                <th>Status</th>
                                <th>Last successful backup</th>
                                <th class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($websites as $site)
                                <tr class="hover:bg-slate-50">
                                    <td>
                                        <a href="{{ route('websites.show', $site) }}" class="font-medium text-slate-900 hover:underline">{{ $site->name }}</a>
                                        <div class="text-xs text-slate-500">{{ parse_url($site->url, PHP_URL_HOST) ?? $site->url }}</div>
                                    </td>
                                    <td><x-health :health="$site->healthState" /></td>
                                    <td class="text-slate-600">
                                        {{ $site->last_backup_at?->diffForHumans() ?? 'Never' }}
                                        @if ($site->backup_frequency && $site->backup_frequency !== 'manual')
                                            <div class="text-xs text-slate-400">{{ ucfirst($site->backup_frequency) }} schedule</div>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('websites.show', $site) }}" class="btn btn-secondary btn-sm">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card" aria-labelledby="activity-title">
                <div class="card-header">
                    <h2 id="activity-title" class="card-title">Recent activity</h2>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($recentActivities as $activity)
                        <li class="px-5 py-3">
                            <div class="flex items-start gap-2.5">
                                <span @class([
                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                    'bg-red-500' => in_array($activity->level, ['danger', 'error'], true),
                                    'bg-amber-500' => $activity->level === 'warning',
                                    'bg-emerald-500' => $activity->level === 'success',
                                    'bg-slate-300' => ! in_array($activity->level, ['danger', 'error', 'warning', 'success'], true),
                                ]) aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <p class="text-sm text-slate-700">{{ $activity->message }}</p>
                                    <p class="mt-0.5 text-xs text-slate-400">
                                        {{ $activity->website?->name ? $activity->website->name.' · ' : '' }}{{ $activity->created_at->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-slate-500">Nothing has happened yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif
</div>
@endsection
