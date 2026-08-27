@extends('layouts.app')

@section('title', 'Edit ' . $website->name)

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
        <a href="{{ route('websites.show', $website) }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800 transition block mb-2">
            &larr; Back to {{ $website->name }} Details
        </a>
        <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">
            Edit Website Settings
        </h1>
        <p class="text-xs text-slate-500 mt-1">Update WordPress URL or API Key credentials.</p>
    </div>

    <!-- Form Panel -->
    <div class="bg-white rounded-xl p-6 sm:p-8 border border-slate-200 shadow-sm">
        <form method="POST" action="{{ route('websites.update', $website) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Site Friendly Name -->
            <div class="space-y-1.5">
                <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Website Name <span class="text-red-600">*</span>
                </label>
                <input type="text" name="name" id="name" value="{{ old('name', $website->name) }}" required class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:border-red-600 focus:bg-white">
            </div>

            <!-- WordPress URL -->
            <div class="space-y-1.5">
                <label for="url" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    WordPress Site URL <span class="text-red-600">*</span>
                </label>
                <input type="url" name="url" id="url" value="{{ old('url', $website->url) }}" required class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 font-mono focus:outline-none focus:border-red-600 focus:bg-white">
            </div>

            <!-- Central Dashboard API Key -->
            <div class="space-y-1.5">
                <label for="api_key" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Plugin Secret API Key (<code class="text-red-600">X-SPSYNC-KEY</code>) <span class="text-red-600">*</span>
                </label>
                <input type="text" name="api_key" id="api_key" value="{{ old('api_key', $website->api_key) }}" required class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 font-mono focus:outline-none focus:border-red-600 focus:bg-white">
            </div>

            <!-- Submit Buttons -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="{{ route('websites.show', $website) }}" class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                    Cancel
                </a>
                <button type="submit" class="px-5 py-2 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                    Update Website
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
