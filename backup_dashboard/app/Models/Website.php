<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'api_key',
        'status',
        'last_sync_at',
        'last_backup_at',
        'system_info',
        'sharepoint_status',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'last_backup_at' => 'datetime',
        'system_info' => 'array',
    ];

    public function backupLogs()
    {
        return $this->hasMany(BackupLog::class)->orderBy('id', 'desc');
    }

    public function restoreLogs()
    {
        return $this->hasMany(RestoreLog::class)->orderBy('id', 'desc');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class)->orderBy('id', 'desc');
    }

    public function latestBackup()
    {
        return $this->hasOne(BackupLog::class)->latestOfMany();
    }

    public function latestRestore()
    {
        return $this->hasOne(RestoreLog::class)->latestOfMany();
    }

    public function getCleanUrlAttribute()
    {
        return rtrim($this->url, '/');
    }
}
