@extends('layouts.app')

@section('title', 'Add website')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <div>
        <a href="{{ route('websites.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← Websites</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Add a website</h1>
        <p class="mt-1 text-sm text-slate-500">Connect a WordPress site that has the SharePoint Backup plugin installed.</p>
    </div>

    <div class="card px-5 py-4 text-sm text-slate-600">
        <p class="font-medium text-slate-900">Before you start</p>
        <ol class="mt-2 list-inside list-decimal space-y-1">
            <li>Install and activate the <strong>SharePoint Backup</strong> plugin on the WordPress site.</li>
            <li>In WordPress, open <strong>SharePoint Backup → Settings → Central Dashboard</strong> and click <strong>Copy key</strong>.</li>
        </ol>
    </div>

    <form method="POST" action="{{ route('websites.store') }}" class="card space-y-5 px-5 py-6">
        @csrf

        <div>
            <label for="name" class="label">Name</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required maxlength="255" placeholder="e.g. Company website" class="input">
        </div>

        <div>
            <label for="url" class="label">Website address</label>
            <input type="url" name="url" id="url" value="{{ old('url') }}" required maxlength="255" placeholder="https://www.example.com" class="input">
            <p class="help">Use the exact address you see in the browser, including <code>https://</code> and <code>www</code> if it has one.</p>
        </div>

        <div>
            <label for="api_key" class="label">Secret API key</label>
            <input type="password" name="api_key" id="api_key" value="" required autocomplete="new-password" class="input font-mono">
            <p class="help">Stored encrypted. It is never shown again after saving.</p>
        </div>

        <fieldset>
            <legend class="label">What is this website for?</legend>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                @foreach (['backup' => ['Live website', 'Monitor it and keep regular backups on SharePoint.'], 'migration' => ['Migration target', 'A new site that will receive a copy of another website.']] as $value => [$title, $description])
                    <label class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3 has-checked:border-slate-900 has-checked:bg-slate-50">
                        <input type="radio" name="purpose" value="{{ $value }}" @checked(old('purpose', 'backup') === $value) class="mt-0.5">
                        <span>
                            <span class="block text-sm font-medium text-slate-900">{{ $title }}</span>
                            <span class="block text-xs text-slate-500">{{ $description }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
            <a href="{{ route('websites.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Add website</button>
        </div>
    </form>
</div>
@endsection
