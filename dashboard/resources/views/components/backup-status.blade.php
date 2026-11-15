@props(['backup'])
@php
    $status = $backup->is_removed_from_sharepoint ? 'missing' : $backup->status;
    [$level, $label] = match ($status) {
        'completed'  => ['ok', 'Saved to SharePoint'],
        'queued'     => ['active', 'Waiting to start'],
        'uploading'  => ['active', 'Uploading '.($backup->total_files ? $backup->uploaded_files.' of '.$backup->total_files.' files' : '')],
        'failed'     => ['critical', 'Failed'],
        'incomplete' => ['critical', 'Incomplete on SharePoint'],
        'missing'    => ['warning', 'Deleted from SharePoint'],
        default      => ['neutral', ucfirst((string) $status)],
    };
@endphp
<x-pill :level="$level" {{ $attributes }}>{{ trim($label) }}</x-pill>
