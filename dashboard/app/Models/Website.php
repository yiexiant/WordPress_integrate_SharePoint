<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'purpose',
        'url',
        'api_key',
        'status',
        'last_sync_at',
        'last_backup_at',
        'system_info',
        'sharepoint_status',
        'site_folder',
        'plugin_version',
        'backup_frequency',
        'next_backup_at',
        'backup_overdue',
        'last_error',
    ];

    /** The plugin API key grants full remote control of the site; never serialize it. */
    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'last_sync_at' => 'datetime',
        'last_backup_at' => 'datetime',
        'next_backup_at' => 'datetime',
        'backup_overdue' => 'boolean',
        'system_info' => 'array',
    ];

    /** Expected gap between automatic backups, or null when the site has no automatic schedule. */
    public function expectedBackupInterval(): ?\Carbon\CarbonInterval
    {
        return match ($this->backup_frequency) {
            'daily'   => \Carbon\CarbonInterval::day(),
            'weekly'  => \Carbon\CarbonInterval::week(),
            'monthly' => \Carbon\CarbonInterval::days(31),
            default   => null,
        };
    }

    /**
     * An automatically scheduled site whose newest successful SharePoint backup is older than its
     * interval plus a grace period. A site that never completed one is measured from registration.
     */
    public function isBackupOverdue(?\Carbon\CarbonInterface $now = null): bool
    {
        $interval = $this->expectedBackupInterval();
        $reference = $this->last_backup_at ?? $this->created_at;
        if ($interval === null || $reference === null) {
            return false;
        }

        $now ??= now();
        $deadline = $now->copy()->sub($interval)->subHours((int) config('services.wordpress.overdue_grace_hours', 6));

        return $reference->lt($deadline);
    }

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

    public function isMigration(): bool
    {
        return ($this->purpose ?? 'backup') === 'migration';
    }

    public function isBackup(): bool
    {
        return ($this->purpose ?? 'backup') === 'backup';
    }
}
