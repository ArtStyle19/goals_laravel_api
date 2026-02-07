<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Task extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'goal_id',
        'title',
        'description',
        'order',
        'scheduled_at',
        'status',
        'is_locked',
        'notes',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_locked' => 'boolean',
            'order' => 'integer',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'end_at',
        'duration_minutes',
    ];


    /**
     * Valid statuses.
     */
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Get the goal that owns the task.
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }


    /**
     * Get the end time of the task (calculated from scheduled_at + duration).
     * Returns ISO8601 string for API responses.
     */
    // public function getEndAtAttribute(): string
    // {
    //     return $this->scheduled_at
    //         ->copy()
    //         ->addMinutes($this->goal->session_duration_minutes)
    //         ->toIso8601String();
    // }



    /**
     * Get the duration in minutes (from parent goal).
     */
    public function getDurationMinutesAttribute(): int
    {
        return $this->goal->session_duration_minutes;
    }

    /**
     * Get the end time of the task.
     */
    public function getEndAtAttribute(): Carbon
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes);
    }

    /**
     * Check if the task is scheduled.
     */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /**
     * Check if the task is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the task is skipped.
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * Check if the task can be rescheduled (not locked and not completed).
     */
    public function canBeRescheduled(): bool
    {
        return !$this->is_locked && $this->isScheduled();
    }

    /**
     * Mark the task as completed.
     */
    public function markAsCompleted(?string $notes = null): void
    {
        $data = [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ];

        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        $this->update($data);
    }

    /**
     * Mark the task as skipped.
     */
    public function markAsSkipped(?string $reason = null): void
    {
        $data = ['status' => self::STATUS_SKIPPED];

        if ($reason !== null) {
            $data['notes'] = $reason;
        }

        $this->update($data);
    }

    /**
     * Lock the task to prevent rescheduling.
     */
    public function lock(): void
    {
        $this->update(['is_locked' => true]);
    }

    /**
     * Unlock the task to allow rescheduling.
     */
    public function unlock(): void
    {
        $this->update(['is_locked' => false]);
    }
}
