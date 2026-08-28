@extends('layouts.app')

@section('title', 'Register New WordPress Website')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
        <a href="{{ route('websites.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800 transition block mb-2">
            Back to Connected Websites
        </a>
        <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">
            Register New WordPress Website
        </h1>
        <p class="text-xs text-slate-500 mt-1">Pair your WordPress site by selecting its purpose, entering its URL, and providing the Secret API Key generated inside the WordPress plugin.</p>
    </div>

    <!-- Form Panel -->
    <div class="bg-white rounded-xl p-6 sm:p-8 border border-slate-200 shadow-sm">
        <form method="POST" action="{{ route('websites.store') }}" class="space-y-6">
            @csrf

            <!-- Website Purpose Selection (Backup vs Migration) -->
            <div class="space-y-3">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Website Purpose <span class="text-red-600">*</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <!-- Option 1: Routine Backup -->
                    <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition select-none bg-slate-50/50 hover:bg-slate-50 border-slate-200 has-checked:border-red-600 has-checked:bg-red-50/20">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-sm font-bold text-slate-900">Routine Backup</span>
                            <input type="radio" name="purpose" value="backup" id="purpose_backup" class="text-red-600 focus:ring-red-500 w-4 h-4" {{ old('purpose', 'backup') === 'backup' ? 'checked' : '' }} onchange="togglePurposeView()">
                        </div>
                        <p class="text-xs text-slate-500">Live production website for regular fleet monitoring, health checks, and scheduled cloud backups.</p>
                    </label>

                    <!-- Option 2: Migration Target -->
                    <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition select-none bg-slate-50/50 hover:bg-slate-50 border-slate-200 has-checked:border-red-600 has-checked:bg-red-50/20">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-sm font-bold text-slate-900">Migration / Restore Target</span>
                            <input type="radio" name="purpose" value="migration" id="purpose_migration" class="text-red-600 focus:ring-red-500 w-4 h-4" {{ old('purpose') === 'migration' ? 'checked' : '' }} onchange="togglePurposeView()">
                        </div>
                        <p class="text-xs text-slate-500">A new or fresh WordPress site intended to receive a migrated backup from an existing website.</p>
                    </label>
                </div>
            </div>

            <!-- Dynamic Migration Tutorial Guide (Clean White & Red Theme, No Icons) -->
            <div id="migration-tutorial-card" class="rounded-xl bg-white border-2 border-red-600 p-5 space-y-4 shadow-sm {{ old('purpose') === 'migration' ? '' : 'hidden' }}">
                <div class="flex items-center justify-between border-b border-red-100 pb-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-red-700">
                        Step-by-Step Migration Guide for New WordPress Site
                    </h3>
                    <span class="text-[11px] font-semibold text-red-700 bg-red-50 border border-red-200 px-2.5 py-0.5 rounded-full">
                        5 Easy Steps
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                    <!-- Step 1 -->
                    <div class="bg-slate-50 rounded-lg p-3 border border-slate-200 space-y-1">
                        <div class="font-bold text-red-700">Step 1: Install Plugin on New Site</div>
                        <p class="text-slate-600 text-[11px] leading-relaxed">
                            On your fresh WordPress, upload <code class="text-slate-900 bg-slate-200 px-1 py-0.5 rounded font-mono">schedule_backup_plugin.zip</code> in Plugins, click Add New, then Upload Plugin, and activate it.
                        </p>
                    </div>

                    <!-- Step 2 -->
                    <div class="bg-slate-50 rounded-lg p-3 border border-slate-200 space-y-1">
                        <div class="font-bold text-red-700">Step 2: Connect SharePoint Storage</div>
                        <p class="text-slate-600 text-[11px] leading-relaxed">
                            In the new WordPress admin, open SharePoint Backup, select Tab 2, and enter the same Azure Tenant, Client ID and Secret to access your cloud backups.
                        </p>
                    </div>

                    <!-- Step 3 -->
                    <div class="bg-slate-50 rounded-lg p-3 border border-slate-200 space-y-1">
                        <div class="font-bold text-red-700">Step 3: Pair Site to Dashboard</div>
                        <p class="text-slate-600 text-[11px] leading-relaxed">
                            Copy the Central Dashboard Secret API Key (<code class="text-slate-900 bg-slate-200 px-1 py-0.5 rounded font-mono">X-SPSYNC-KEY</code>) from Tab 1 and paste it into the field below.
                        </p>
                    </div>

                    <!-- Step 4 -->
                    <div class="bg-slate-50 rounded-lg p-3 border border-slate-200 space-y-1">
                        <div class="font-bold text-red-700">Step 4: Restore Backup from SharePoint</div>
                        <p class="text-slate-600 text-[11px] leading-relaxed">
                            Once registered, open the site details, select the source backup archive, and click Restore Site to unpack files and import database tables.
                        </p>
                    </div>
                </div>

                <!-- Step 5 Highlight -->
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-[11px] text-red-900">
                    <strong class="text-red-800 block mb-0.5">Step 5: Automated Database URL Update</strong>
                    After the cloud restore finishes, use the built-in Database URL Update tool on the site page to replace the old domain URLs with this new website URL across all database tables safely without breaking serialized data.
                </div>
            </div>

            <!-- Site Friendly Name -->
            <div class="space-y-1.5">
                <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Website Name <span class="text-red-600">*</span>
                </label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" required placeholder="e.g. New Staging Store (Migrated)" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:border-red-600 focus:bg-white">
                <p class="text-[11px] text-slate-500">A recognizable label for this WordPress installation.</p>
            </div>

            <!-- WordPress URL -->
            <div class="space-y-1.5">
                <label for="url" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    WordPress Site URL <span class="text-red-600">*</span>
                </label>
                <input type="url" name="url" id="url" value="{{ old('url') }}" required placeholder="https://newsite.com or http://localhost/newsite" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 font-mono focus:outline-none focus:border-red-600 focus:bg-white">
                <p class="text-[11px] text-slate-500">The full root URL of the new WordPress site (must include http:// or https://).</p>
            </div>

            <!-- Central Dashboard API Key -->
            <div class="space-y-1.5">
                <label for="api_key" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Plugin Secret API Key (<code class="text-red-600">X-SPSYNC-KEY</code>) <span class="text-red-600">*</span>
                </label>
                <input type="text" name="api_key" id="api_key" value="{{ old('api_key') }}" required placeholder="Paste the 64-character API Key from WordPress admin" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 font-mono focus:outline-none focus:border-red-600 focus:bg-white">
                <div class="rounded-lg bg-slate-50 border border-slate-200 p-3 text-[11px] text-slate-600 space-y-1 mt-2">
                    <span class="font-bold text-slate-800 block">How to find your API Key:</span>
                    <span>1. In your WordPress admin sidebar, click <strong>SharePoint Backup</strong>.</span><br>
                    <span>2. On the <strong>Overview & Testing</strong> tab, copy the <strong>Central Dashboard Secret API Key</strong>.</span>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="{{ route('websites.index') }}" class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                    Cancel
                </a>
                <button type="submit" class="px-5 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                    Save & Test Connection
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function togglePurposeView() {
        const isMigration = document.getElementById('purpose_migration').checked;
        const tutorialCard = document.getElementById('migration-tutorial-card');
        if (isMigration) {
            tutorialCard.classList.remove('hidden');
        } else {
            tutorialCard.classList.add('hidden');
        }
    }
</script>
@endpush
