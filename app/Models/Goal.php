<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'deadline',
        'session_duration_minutes',
        'availability_rules',
        'status',
        'total_sessions',
        'total_hours',
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
            'deadline' => 'date',
            'availability_rules' => 'array',
            'total_sessions' => 'integer',
            'total_hours' => 'decimal:1',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Valid session durations in minutes.
     */
    public const VALID_SESSION_DURATIONS = [30, 45, 60, 90, 120];

    /**
     * Valid statuses.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';

    /**
     * Get the user that owns the goal.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tasks for the goal.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get pending tasks (not completed or skipped).
     */
    public function pendingTasks(): HasMany
    {
        return $this->hasMany(Task::class)
            ->where('status', 'scheduled')
            ->orderBy('order');
    }

    /**
     * Get completed tasks.
     */
    public function completedTasks(): HasMany
    {
        return $this->hasMany(Task::class)
            ->where('status', 'completed');
    }

    /**
     * Check if the goal is in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if the goal is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the goal is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Activate the goal.
     */
    public function activate(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Complete the goal.
     */
    public function complete(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Reactivate a completed goal (e.g. after a replan).
     */
    public function reactivate(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'completed_at' => null,
        ]);
    }

    /**
     * Calculate and update the total sessions and hours.
     */
    public function calculateCapacity(int $totalSessions): void
    {
        $totalHours = ($totalSessions * $this->session_duration_minutes) / 60;

        $this->update([
            'total_sessions' => $totalSessions,
            'total_hours' => round($totalHours, 1),
        ]);
    }
}
