<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Overview') · Backup Dashboard</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col font-sans text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-6 px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-8">
                <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="flex items-center gap-2.5">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-red-600 text-xs font-bold text-white">SP</span>
                    <span class="text-sm font-semibold text-slate-900">Backup Dashboard</span>
                </a>

                @auth
                    <nav class="flex items-center gap-1 text-sm">
                        @foreach (['dashboard' => 'Overview', 'websites.index' => 'Websites'] as $route => $label)
                            @php $active = request()->routeIs($route === 'dashboard' ? 'dashboard' : 'websites.*'); @endphp
                            <a href="{{ route($route) }}" @class([
                                'rounded-md px-3 py-2 font-medium',
                                'bg-slate-100 text-slate-900' => $active,
                                'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $active,
                            ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>
                @endauth
            </div>

            @auth
                <div class="flex items-center gap-3">
                    <a href="{{ route('websites.create') }}" class="btn btn-primary hidden sm:inline-flex">Add website</a>
                    <span class="hidden text-sm text-slate-500 lg:inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">Sign out</button>
                    </form>
                </div>
            @endauth
        </div>
    </header>

    <main class="flex-1 py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @php
                $flashes = [
                    'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                    'info'    => 'border-sky-200 bg-sky-50 text-sky-900',
                    'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
                    'error'   => 'border-red-200 bg-red-50 text-red-900',
                ];
            @endphp
            @foreach ($flashes as $type => $classes)
                @if (session($type))
                    <div role="{{ $type === 'error' ? 'alert' : 'status' }}" class="mb-6 flex items-start justify-between gap-4 rounded-lg border px-4 py-3 text-sm {{ $classes }}">
                        <p>{{ session($type) }}</p>
                        <button type="button" onclick="this.parentElement.remove()" class="text-lg leading-none opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
                    </div>
                @endif
            @endforeach

            @if ($errors->any())
                <div role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <p class="font-medium">Please fix the following:</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>

    <footer class="border-t border-slate-200 bg-white py-4 text-center text-xs text-slate-500">
        WordPress SharePoint Backup · Central Dashboard
    </footer>

    {{-- Shared confirmation dialog for any <form data-confirm="..."> (see resources/js/app.js). --}}
    <dialog id="confirm-dialog" class="m-auto w-full max-w-md rounded-xl p-0 shadow-xl backdrop:bg-slate-900/50">
        <div class="space-y-4 p-6">
            <h2 id="confirm-title" class="text-lg font-semibold text-slate-900"></h2>
            <p id="confirm-message" class="text-sm whitespace-pre-line text-slate-600"></p>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" id="confirm-cancel" class="btn btn-secondary">Cancel</button>
                <button type="button" id="confirm-ok" class="btn btn-primary"></button>
            </div>
        </div>
    </dialog>

    @stack('scripts')
</body>
</html>
