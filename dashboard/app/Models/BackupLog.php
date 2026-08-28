<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'backup_id',
        'status',
        'total_files',
        'uploaded_files',
        'file_size',
        'is_removed_from_sharepoint',
        'verified_at',
    ];

    protected $casts = [
        'is_removed_from_sharepoint' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function website()
    {
        return $this->belongsTo(Website::class);
    }

    public function getFormattedSizeAttribute()
    {
        if (!$this->file_size) {
            return '-';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($this->file_size, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
