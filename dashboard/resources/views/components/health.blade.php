@props(['health'])
<x-pill :level="$health->level" {{ $attributes }} title="{{ $health->reason }}">{{ $health->label }}</x-pill>
