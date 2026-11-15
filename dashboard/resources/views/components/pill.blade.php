@props(['level' => 'neutral'])
@php
    $styles = [
        'ok'       => ['bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'bg-emerald-500'],
        'warning'  => ['bg-amber-50 text-amber-800 ring-amber-600/25', 'bg-amber-500'],
        'critical' => ['bg-red-50 text-red-700 ring-red-600/20', 'bg-red-500'],
        'active'   => ['bg-sky-50 text-sky-700 ring-sky-600/20', 'bg-sky-500 animate-pulse'],
        'neutral'  => ['bg-slate-50 text-slate-600 ring-slate-500/20', 'bg-slate-400'],
        'unknown'  => ['bg-slate-50 text-slate-600 ring-slate-500/20', 'bg-slate-400'],
    ][$level] ?? ['bg-slate-50 text-slate-600 ring-slate-500/20', 'bg-slate-400'];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset', $styles[0]]) }}>
    <span class="size-1.5 rounded-full {{ $styles[1] }}" aria-hidden="true"></span>
    {{ $slot }}
</span>
