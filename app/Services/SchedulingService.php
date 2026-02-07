<?php

namespace App\Services;

use App\Models\Goal;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * SchedulingService - Servicio de programación de sesiones
 *
 * Responsabilidad única: Calcular capacidad y generar slots temporales.
 *
 * IMPORTANTE - Limitaciones de diseño:
 * - Los bloques de disponibilidad NO pueden cruzar medianoche (ej: 22:00-02:00)
 * - Si necesitas sesiones nocturnas, usa bloques separados (ej: 22:00-23:59)
 * - Los bloques deben ser múltiplos de la duración de sesión
 */
class SchedulingService
{
    /**
     * Map of day names in English to use with Carbon.
     */
    private const DAY_MAP = [
        'monday' => Carbon::MONDAY,
        'tuesday' => Carbon::TUESDAY,
        'wednesday' => Carbon::WEDNESDAY,
        'thursday' => Carbon::THURSDAY,
        'friday' => Carbon::FRIDAY,
        'saturday' => Carbon::SATURDAY,
        'sunday' => Carbon::SUNDAY,
    ];

    /**
     * Calculate the total capacity of a goal.
     * Returns total sessions, total hours, and available slots.
     *
     * @param Goal $goal
     * @param Carbon|null $fromDate Starting date (default: today in user's timezone)
     * @param string $timezone IANA timezone of the user (e.g. "America/Mexico_City")
     * @return array{total_sessions: int, total_hours: float, slots: array}
     */
    public function calculateCapacity(Goal $goal, ?Carbon $fromDate = null, string $timezone = 'UTC'): array
    {
        $slots = $this->generateSlots($goal, $fromDate, $timezone);

        $totalSessions = count($slots);
        $totalHours = round(($totalSessions * $goal->session_duration_minutes) / 60, 1);

        return [
            'total_sessions' => $totalSessions,
            'total_hours' => $totalHours,
            'slots' => $slots,
        ];
    }

    /**
     * Generate all available slots from a starting date until the deadline.
     * Each slot represents one session.
     *
     * Availability rules are interpreted in the user's local timezone.
     * Generated slot datetimes are returned in UTC for consistent storage.
     *
     * @param Goal $goal
     * @param Carbon|null $fromDate Starting date (default: today in user's timezone)
     * @param string $timezone IANA timezone of the user (e.g. "America/Mexico_City")
     * @return array Array of slots with date, start, end times, and UTC datetime
     */
    public function generateSlots(Goal $goal, ?Carbon $fromDate = null, string $timezone = 'UTC'): array
    {
        $slots = [];

        // Start from today in the user's timezone, or the given date
        $startDate = $fromDate ?? Carbon::today($timezone);
        $endDate = Carbon::parse($goal->deadline);

        // If deadline is in the past, return empty
        if ($endDate->lt($startDate)) {
            return [];
        }

        // Get availability rules indexed by day
        $rulesByDay = $this->indexRulesByDay($goal->availability_rules);

        // Iterate through each day from start to deadline
        $period = CarbonPeriod::create($startDate, $endDate);

        foreach ($period as $date) {
            $dayName = strtolower($date->format('l')); // monday, tuesday, etc.

            // Skip days without availability
            if (!isset($rulesByDay[$dayName])) {
                continue;
            }

            // Generate slots for each availability block on this day
            foreach ($rulesByDay[$dayName] as $rule) {
                $blockSlots = $this->generateSlotsFromBlock(
                    $date,
                    $rule['start'],
                    $rule['end'],
                    $goal->session_duration_minutes,
                    $timezone
                );

                $slots = array_merge($slots, $blockSlots);
            }
        }

        // Filter out slots that are in the past (compare in UTC since datetimes are UTC)
        $now = Carbon::now('UTC');
        $slots = array_filter($slots, function ($slot) use ($now) {
            return Carbon::parse($slot['datetime'])->gt($now);
        });

        // Re-index and return
        return array_values($slots);
    }

    /**
     * Assign dates to tasks based on available slots.
     * Tasks are assigned in order.
     *
     * @param array $tasks Array of task data with at least 'order' and 'title'
     * @param array $slots Available slots from generateSlots()
     * @return array Tasks with 'scheduled_at' assigned
     */
    public function assignDates(array $tasks, array $slots): array
    {
        // Sort tasks by order
        usort($tasks, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $result = [];
        $slotIndex = 0;

        foreach ($tasks as $task) {
            if ($slotIndex < count($slots)) {
                $task['scheduled_at'] = $slots[$slotIndex]['datetime'];
                $slotIndex++;
            } else {
                // No more slots available
                $task['scheduled_at'] = null;
            }

            $result[] = $task;
        }

        return $result;
    }

    /**
     * Validate that all availability blocks are valid.
     * - Must be multiples of session duration
     * - End time must be after start time (no midnight crossing)
     * - Blocks should not overlap (warning only)
     *
     * @param array $rules Availability rules
     * @param int $sessionDuration Session duration in minutes
     * @return array Validation result with 'valid' boolean, 'errors' array, and 'warnings' array
     */
    public function validateBlocks(array $rules, int $sessionDuration): array
    {
        $errors = [];
        $warnings = [];

        foreach ($rules as $index => $rule) {
            $day = $rule['day'] ?? 'unknown';
            $startStr = $rule['start'] ?? '';
            $endStr = $rule['end'] ?? '';

            // Validate time format
            if (!preg_match('/^\d{2}:\d{2}$/', $startStr) || !preg_match('/^\d{2}:\d{2}$/', $endStr)) {
                $errors[] = "Formato de hora inválido en el bloque {$day}: {$startStr}-{$endStr}. Use formato HH:MM";
                continue;
            }

            // Validate that end time is after start time (no midnight crossing)
            if ($endStr <= $startStr) {
                $errors[] = "El bloque {$day} {$startStr}-{$endStr} es inválido: la hora de fin debe ser posterior a la hora de inicio. Los bloques no pueden cruzar medianoche.";
                continue;
            }

            // Calculate block duration using a fixed reference date
            $refDate = '2000-01-01';
            $start = Carbon::parse("{$refDate} {$startStr}");
            $end = Carbon::parse("{$refDate} {$endStr}");
            $blockMinutes = $start->diffInMinutes($end);

            if ($blockMinutes < $sessionDuration) {
                $errors[] = "El bloque {$day} {$startStr}-{$endStr} ({$blockMinutes} min) es menor que la duración de sesión ({$sessionDuration} min)";
            } elseif ($blockMinutes % $sessionDuration !== 0) {
                $warnings[] = "El bloque {$day} {$startStr}-{$endStr} ({$blockMinutes} min) no es múltiplo exacto de la duración de sesión ({$sessionDuration} min). Se usarán " . intdiv($blockMinutes, $sessionDuration) . " sesiones.";
            }
        }

        // Check for overlapping blocks on the same day
        $blocksByDay = [];
        foreach ($rules as $rule) {
            $day = strtolower($rule['day'] ?? '');
            if (!isset($blocksByDay[$day])) {
                $blocksByDay[$day] = [];
            }
            $blocksByDay[$day][] = $rule;
        }

        foreach ($blocksByDay as $day => $dayBlocks) {
            if (count($dayBlocks) > 1) {
                // Sort by start time
                usort($dayBlocks, fn($a, $b) => strcmp($a['start'], $b['start']));

                for ($i = 0; $i < count($dayBlocks) - 1; $i++) {
                    $currentEnd = $dayBlocks[$i]['end'];
                    $nextStart = $dayBlocks[$i + 1]['start'];

                    if ($currentEnd > $nextStart) {
                        $warnings[] = "Los bloques en {$day} ({$dayBlocks[$i]['start']}-{$currentEnd} y {$nextStart}-{$dayBlocks[$i + 1]['end']}) se solapan. Esto puede causar duplicación de slots.";
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Index availability rules by day name for quick lookup.
     *
     * @param array $rules
     * @return array Rules indexed by day name
     */
    private function indexRulesByDay(array $rules): array
    {
        $indexed = [];

        foreach ($rules as $rule) {
            $day = strtolower($rule['day']);
            if (!isset($indexed[$day])) {
                $indexed[$day] = [];
            }
            $indexed[$day][] = $rule;
        }

        // Sort rules within each day by start time
        foreach ($indexed as $day => &$dayRules) {
            usort($dayRules, fn($a, $b) => strcmp($a['start'], $b['start']));
        }

        return $indexed;
    }

    /**
     * Generate slots from a time block.
     *
     * Creates slots in the user's local timezone, then converts to UTC for storage.
     *
     * @param Carbon $date The date
     * @param string $startTime Start time (HH:MM) in user's local timezone
     * @param string $endTime End time (HH:MM) in user's local timezone
     * @param int $sessionDuration Duration per session in minutes
     * @param string $timezone IANA timezone of the user
     * @return array Array of slots with UTC datetimes
     */
    private function generateSlotsFromBlock(
        Carbon $date,
        string $startTime,
        string $endTime,
        int $sessionDuration,
        string $timezone = 'UTC'
    ): array {
        $slots = [];

        // Create times in user's local timezone
        $blockStart = Carbon::parse($date->format('Y-m-d') . ' ' . $startTime, $timezone);
        $blockEnd = Carbon::parse($date->format('Y-m-d') . ' ' . $endTime, $timezone);

        $blockMinutes = abs($blockEnd->diffInMinutes($blockStart));
        $sessionsInBlock = intdiv($blockMinutes, $sessionDuration);

        for ($i = 0; $i < $sessionsInBlock; $i++) {
            $slotStart = $blockStart->copy()->addMinutes($i * $sessionDuration);
            $slotEnd = $slotStart->copy()->addMinutes($sessionDuration);

            // Convert to UTC for storage
            $slotStartUtc = $slotStart->copy()->utc();

            $slots[] = [
                'date' => $date->format('Y-m-d'),
                'start' => $slotStart->format('H:i'),
                'end' => $slotEnd->format('H:i'),
                'datetime' => $slotStartUtc->toDateTimeString(),
            ];
        }

        return $slots;
    }
}
