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

    /**
     * Overall health in plain language. Uses the eager-loaded latestBackup when present.
     */
    public function health(): \App\Support\SiteHealth
    {
        $health = fn (string $level, string $label, string $reason) => new \App\Support\SiteHealth($level, $label, $reason);
        $lastBackup = $this->last_backup_at ? 'Last successful backup '.$this->last_backup_at->diffForHumans().'.' : 'No successful backup recorded yet.';

        if ($this->last_sync_at === null && $this->last_error === null && $this->status !== 'online') {
            return $health('unknown', 'Not checked yet', 'Run "Check connection" to confirm the dashboard can reach this website.');
        }
        if ($this->status !== 'online') {
            return $health('critical', 'Unreachable', $this->last_error ?: 'The dashboard could not reach the WordPress plugin.');
        }
        if ($this->sharepoint_status === 'error') {
            return $health('critical', 'SharePoint error', 'WordPress could not sign in to SharePoint. Check the Microsoft Entra ID credentials in the plugin settings.');
        }
        if ($this->backup_overdue) {
            return $health('critical', 'Backup overdue', $lastBackup.' This site is scheduled to back up '.($this->backup_frequency ?? 'automatically').'.');
        }

        $latest = $this->latestBackup;
        if ($latest && in_array($latest->status, ['failed', 'incomplete', 'missing'], true)) {
            $labels = ['failed' => 'Last backup failed', 'incomplete' => 'Backup incomplete', 'missing' => 'Backup missing'];
            $reasons = [
                'failed' => 'The most recent backup did not finish uploading to SharePoint. Check the WordPress logs.',
                'incomplete' => 'The most recent backup is missing files on SharePoint and cannot be fully restored.',
                'missing' => 'The most recent backup was deleted from SharePoint.',
            ];

            return $health('warning', $labels[$latest->status], $reasons[$latest->status]);
        }

        if (! $this->isMigration() && in_array($this->backup_frequency, [null, 'manual'], true)) {
            return $health('warning', 'No automatic backups', $this->backup_frequency === null
                ? 'The schedule is unknown. Update the plugin to 1.4.0+ and check the connection.'
                : 'Automatic SharePoint backups are turned off in the WordPress plugin.');
        }

        $next = $this->next_backup_at ? ' Next backup '.$this->next_backup_at->diffForHumans().'.' : '';

        return $health('ok', 'Healthy', $lastBackup.$next);
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
