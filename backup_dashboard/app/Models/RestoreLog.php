<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class RestoreLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'backup_id',
        'restore_job_id',
        'status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function website()
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Get the restoration duration in formatted human string (e.g., "1m 24s", "45s")
     */
    public function getFormattedDurationAttribute()
    {
        $seconds = $this->duration_seconds;

        // If not explicitly set but timestamps exist, calculate on the fly
        if ($seconds === null && $this->started_at) {
            $end = $this->completed_at ?? now();
            $seconds = $this->started_at->diffInSeconds($end);
        }

        if ($seconds === null || $seconds <= 0) {
            return $this->status === 'completed' ? '< 1s' : ($this->status === 'failed' ? 'Interrupted' : 'In Progress...');
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }
}
