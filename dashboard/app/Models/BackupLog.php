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

    /**
     * When the backup was taken. Backup IDs embed the WordPress-local timestamp
     * (spsync-backup-YYYYMMDD-HHMMSS); fall back to when the dashboard first saw it.
     */
    public function getTakenAtAttribute(): \Illuminate\Support\Carbon
    {
        if (preg_match('/^spsync-backup-(\d{8}-\d{6})$/', (string) $this->backup_id, $matches)) {
            $parsed = \Illuminate\Support\Carbon::createFromFormat('Ymd-His', $matches[1]);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return $this->created_at ?? now();
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
