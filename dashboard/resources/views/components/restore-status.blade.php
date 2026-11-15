@props(['restore'])
@php
    [$level, $label] = match ($restore->status) {
        'completed'          => ['ok', 'Restored'],
        'failed'             => ['critical', 'Failed, rolled back'],
        'pending'            => ['active', 'Starting'],
        'downloading'        => ['active', 'Downloading'],
        'extracting'         => ['active', 'Restoring files'],
        'restoring_database' => ['active', 'Restoring database'],
        'cleanup'            => ['active', 'Finishing'],
        default              => ['neutral', ucfirst(str_replace('_', ' ', (string) $restore->status))],
    };
@endphp
<x-pill :level="$level" {{ $attributes }}>{{ $label }}</x-pill>
