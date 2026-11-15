@extends('layouts.app')

@section('title', 'Edit '.$website->name)

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <div>
        <a href="{{ route('websites.show', $website) }}" class="text-sm text-slate-500 hover:text-slate-900">← {{ $website->name }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Edit website</h1>
    </div>

    <form method="POST" action="{{ route('websites.update', $website) }}" class="card space-y-5 px-5 py-6">
        @csrf
        @method('PUT')

        <div>
            <label for="name" class="label">Name</label>
            <input type="text" name="name" id="name" value="{{ old('name', $website->name) }}" required maxlength="255" class="input">
        </div>

        <div>
            <label for="url" class="label">Website address</label>
            <input type="url" name="url" id="url" value="{{ old('url', $website->url) }}" required maxlength="255" class="input">
        </div>

        <div>
            <label for="api_key" class="label">Secret API key</label>
            <input type="password" name="api_key" id="api_key" value="" autocomplete="new-password" placeholder="Leave blank to keep the current key" class="input font-mono">
            <p class="help">The saved key is encrypted and never shown. Paste a new one only after regenerating it in WordPress.</p>
        </div>

        <fieldset>
            <legend class="label">What is this website for?</legend>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                @foreach (['backup' => ['Live website', 'Monitor it and keep regular backups on SharePoint.'], 'migration' => ['Migration target', 'A new site that will receive a copy of another website.']] as $value => [$title, $description])
                    <label class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3 has-checked:border-slate-900 has-checked:bg-slate-50">
                        <input type="radio" name="purpose" value="{{ $value }}" @checked(old('purpose', $website->purpose ?? 'backup') === $value) class="mt-0.5">
                        <span>
                            <span class="block text-sm font-medium text-slate-900">{{ $title }}</span>
                            <span class="block text-xs text-slate-500">{{ $description }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
            <a href="{{ route('websites.show', $website) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>

    <section class="card border-red-200" aria-labelledby="danger-title">
        <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
            <div>
                <h2 id="danger-title" class="text-sm font-semibold text-red-700">Remove from dashboard</h2>
                <p class="mt-0.5 text-sm text-slate-500">Deletes this website's history from the dashboard. The WordPress site and its SharePoint backups are not touched.</p>
            </div>
            <form method="POST" action="{{ route('websites.destroy', $website) }}"
                  data-confirm="Remove {{ $website->name }} and its backup/restore history from this dashboard? The WordPress site and its SharePoint backups stay as they are."
                  data-confirm-title="Remove website?" data-confirm-button="Remove website" data-confirm-danger>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Remove website</button>
            </form>
        </div>
    </section>
</div>
@endsection
