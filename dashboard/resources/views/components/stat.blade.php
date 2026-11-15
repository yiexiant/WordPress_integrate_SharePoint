@props(['label', 'value', 'tone' => 'default'])
<div {{ $attributes->class('card px-5 py-4') }}>
    <dt class="text-sm text-slate-500">{{ $label }}</dt>
    <dd @class([
        'mt-1 text-3xl font-semibold tracking-tight',
        'text-slate-900' => $tone === 'default',
        'text-emerald-600' => $tone === 'ok',
        'text-red-600' => $tone === 'critical',
        'text-sky-600' => $tone === 'active',
    ])>{{ $value }}</dd>
    @isset($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endisset
</div>
