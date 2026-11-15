@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<div class="mx-auto mt-8 max-w-sm">
    <div class="card px-6 py-8">
        <h1 class="text-xl font-semibold text-slate-900">Sign in</h1>
        <p class="mt-1 text-sm text-slate-500">Backup dashboard for your WordPress websites.</p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input">
            </div>
            <div>
                <label for="password" class="label">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300"> Keep me signed in
            </label>
            <button type="submit" class="btn btn-primary w-full">Sign in</button>
        </form>
    </div>
    <p class="mt-4 text-center text-xs text-slate-500">Need an account? Ask an administrator to run <code>php artisan dashboard:user</code>.</p>
</div>
@endsection
