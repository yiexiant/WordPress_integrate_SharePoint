@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<div class="max-w-md mx-auto mt-10">
    <div class="white-card rounded-2xl p-8">
        <h1 class="text-xl font-bold text-slate-900">Sign in</h1>
        <p class="text-sm text-slate-500 mt-1">Operators only. This dashboard can start backups, restores and URL rewrites on every connected website.</p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm font-semibold text-slate-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500">
            </div>
            <div>
                <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-red-600"> Remember me
            </label>
            <button type="submit" class="w-full px-4 py-2 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 shadow-sm transition">
                Sign in
            </button>
        </form>
        <p class="text-xs text-slate-400 mt-6">No account yet? An administrator creates one with <code class="font-mono">php artisan dashboard:user</code>.</p>
    </div>
</div>
@endsection
