<?php

namespace Zap\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Zap\Builders\ScheduleBuilder;
use Zap\Enums\ScheduleTypes;
use Zap\Models\Schedule;
use Zap\Services\ConflictDetectionService;

/**
 * Trait HasSchedules
 *
 * This trait provides scheduling capabilities to any Eloquent model.
 * Use this trait in models that need to be schedulable.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasSchedules
{
    /**
     * Get all schedules for this model.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function schedules(): MorphMany
    {
        return $this->morphMany(Schedule::class, 'schedulable');
    }

    /**
     * Get only active schedules.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function activeSchedules(): MorphMany
    {
        return $this->schedules()->active();
    }

    /**
     * Get availability schedules.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function availabilitySchedules(): MorphMany
    {
        return $this->schedules()->availability();
    }

    /**
     * Get appointment schedules.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function appointmentSchedules(): MorphMany
    {
        return $this->schedules()->appointments();
    }

    /**
     * Get blocked schedules.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function blockedSchedules(): MorphMany
    {
        return $this->schedules()->blocked();
    }

    /**
     * Get schedules for a specific date.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function schedulesForDate(string $date): MorphMany
    {
        return $this->schedules()->forDate($date);
    }

    /**
     * Get schedules within a date range.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function schedulesForDateRange(string $startDate, string $endDate): MorphMany
    {
        return $this->schedules()->forDateRange($startDate, $endDate);
    }

    /**
     * Get recurring schedules.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function recurringSchedules(): MorphMany
    {
        return $this->schedules()->recurring();
    }

    /**
     * Get schedules of a specific type.
     *
     * @return MorphMany<Schedule, $this>
     */
    public function schedulesOfType(string $type): MorphMany
    {
        return $this->schedules()->ofType($type);
    }

    /**
     * Create a new schedule builder for this model.
     */
    public function createSchedule(): ScheduleBuilder
    {
        return (new ScheduleBuilder)->for($this);
    }

    /**
     * Check if this model has any schedule conflicts with the given schedule.
     */
    public function hasScheduleConflict(Schedule $schedule): bool
    {
        return app(ConflictDetectionService::class)->hasConflicts($schedule);
    }

    /**
     * Find all schedules that conflict with the given schedule.
     */
    public function findScheduleConflicts(Schedule $schedule): array
    {
        return app(ConflictDetectionService::class)->findConflicts($schedule);
    }

    /**
     * Check if this model is available during a specific time period.
     */
    public function isAvailableAt(string $date, string $startTime, string $endTime): bool
    {
        // Get all active schedules for this model on this date
        $schedules = \Zap\Models\Schedule::where('schedulable_type', get_class($this))
            ->where('schedulable_id', $this->getKey())
            ->active()
            ->forDate($date)
            ->with('periods')
            ->get();

        foreach ($schedules as $schedule) {
            $shouldBlock = $schedule->schedule_type === null
                || $schedule->schedule_type->is(ScheduleTypes::CUSTOM)
                || $schedule->preventsOverlaps();

            if ($shouldBlock && $this->scheduleBlocksTime($schedule, $date, $startTime, $endTime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a specific schedule blocks the given time period.
     */
    protected function scheduleBlocksTime(\Zap\Models\Schedule $schedule, string $date, string $startTime, string $endTime): bool
    {
        if (! $schedule->isActiveOn($date)) {
            return false;
        }

        if ($schedule->is_recurring) {
            return $this->recurringScheduleBlocksTime($schedule, $date, $startTime, $endTime);
        }

        // For non-recurring schedules, check stored periods
        return $schedule->periods()->overlapping($date, $startTime, $endTime)->exists();
    }

    /**
     * Check if a recurring schedule blocks the given time period.
     */
    protected function recurringScheduleBlocksTime(\Zap\Models\Schedule $schedule, string $date, string $startTime, string $endTime): bool
    {
        $checkDate = \Carbon\Carbon::parse($date);

        // Check if this date should have a recurring instance
        if (! $this->shouldCreateRecurringInstance($schedule, $checkDate)) {
            return false;
        }

        // Get the base periods and check if any would overlap on this date
        $basePeriods = $schedule->periods;

        foreach ($basePeriods as $basePeriod) {
            if ($this->timePeriodsOverlap($basePeriod->start_time, $basePeriod->end_time, $startTime, $endTime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a recurring instance should be created for the given date.
     */
    protected function shouldCreateRecurringInstance(\Zap\Models\Schedule $schedule, \Carbon\Carbon $date): bool
    {
        $frequency = $schedule->frequency;
        $config = $schedule->frequency_config ?? [];

        switch ($frequency) {
            case 'daily':
                return true;

            case 'weekly':
                $allowedDays = $config['days'] ?? ['monday'];
                $allowedDayNumbers = array_map(function ($day) {
                    return match (strtolower($day)) {
                        'sunday' => 0,
                        'monday' => 1,
                        'tuesday' => 2,
                        'wednesday' => 3,
                        'thursday' => 4,
                        'friday' => 5,
                        'saturday' => 6,
                        default => 1, // Default to Monday
                    };
                }, $allowedDays);

                return in_array($date->dayOfWeek, $allowedDayNumbers);

            case 'monthly':
                $dayOfMonth = $config['day_of_month'] ?? $schedule->start_date->day;

                return $date->day === $dayOfMonth;

            default:
                return false;
        }
    }

    /**
     * Check if two time periods overlap.
     */
    protected function timePeriodsOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        return $start1 < $end2 && $end1 > $start2;
    }

    /**
     * Get available time slots for a specific date.
     */
    public function getAvailableSlots(
        string $date,
        string $dayStart = '09:00',
        string $dayEnd = '17:00',
        int $slotDuration = 60
    ): array {
        // Validate inputs to prevent infinite loops
        if ($slotDuration <= 0) {
            return [];
        }

        $slots = [];

        $availabilitySchedules = \Zap\Models\Schedule::where('schedulable_type', get_class($this))
            ->where('schedulable_id', $this->getKey())
            ->active()
            ->availability()
            ->forDate(now()->format('Y-m-d'))
            ->with('periods')
            ->get();

        $periods = [
            'morning' => [
                'start_time' => $dayStart,
                'end_time' => null,
            ],
            'afternoon' => [
                'start_time' => null,
                'end_time' => $dayEnd,
            ],
        ];

        // Define the cutoff time between morning and afternoon (e.g., 12:00)
        $morningAfternoonCutoff = '12:00:00';

        // Get the start and end times from availability schedules
        if ($availabilitySchedules->isNotEmpty()) {
            $availabilitySchedules->flatMap(function ($schedule) {
                return $schedule->periods;
            })->each(function ($period) use (&$periods, $morningAfternoonCutoff) {

                $startTime = $period->start_time;
                $endTime = $period->end_time;

                // Skip if both times are null
                if (! $startTime && ! $endTime) {
                    return;
                }

                // Determine if this period is morning, afternoon, or spans both
                $isMorningPeriod = $startTime && $startTime < $morningAfternoonCutoff;
                $isAfternoonPeriod = $endTime && $endTime >= $morningAfternoonCutoff;

                // Handle morning periods
                if ($isMorningPeriod) {
                    // Update morning start time (find earliest)
                    // if ($startTime) {
                    if (! $periods['morning']['start_time'] || $startTime < $periods['morning']['start_time']) {
                        $periods['morning']['start_time'] = $startTime;
                    }
                    // }

                    // Update morning end time (find latest, but cap at cutoff if period spans both)
                    $morningEndTime = ($isAfternoonPeriod) ? $morningAfternoonCutoff : $endTime;
                    if ($morningEndTime) {
                        if (! $periods['morning']['end_time'] || $morningEndTime > $periods['morning']['end_time']) {
                            $periods['morning']['end_time'] = $morningEndTime;
                        }
                    }
                }

                // Handle afternoon periods
                if ($isAfternoonPeriod) {
                    // Update afternoon start time (find earliest, but start from cutoff if period spans both)
                    $afternoonStartTime = ($isMorningPeriod) ? $morningAfternoonCutoff : $startTime;
                    if ($afternoonStartTime) {
                        if (! $periods['afternoon']['start_time'] || $afternoonStartTime < $periods['afternoon']['start_time']) {
                            $periods['afternoon']['start_time'] = $afternoonStartTime;
                        }
                    }

                    // Update afternoon end time (find latest)
                    // if ($endTime) {
                    if (! $periods['afternoon']['end_time'] || $endTime > $periods['afternoon']['end_time']) {
                        $periods['afternoon']['end_time'] = $endTime;
                        // }
                    }
                }
            });
        }

        // Create Carbon instances for the final times
        $morningStart = null;
        $morningEnd = null;
        $afternoonStart = null;
        $afternoonEnd = null;

        if (filled($periods['morning']['start_time'])) {
            $morningStart = \Carbon\Carbon::parse($date.' '.$periods['morning']['start_time']);
        }
        if (filled($periods['morning']['end_time'])) {
            $morningEnd = \Carbon\Carbon::parse($date.' '.$periods['morning']['end_time']);
        }
        if (filled($periods['afternoon']['start_time'])) {
            $afternoonStart = \Carbon\Carbon::parse($date.' '.$periods['afternoon']['start_time']);
        }
        if (filled($periods['afternoon']['end_time'])) {
            $afternoonEnd = \Carbon\Carbon::parse($date.' '.$periods['afternoon']['end_time']);
        }

        // Safety counter to prevent infinite loops (max 1440 minutes in a day / min slot duration)
        $maxIterations = 1440;
        $iterations = 0;

        while ($morningStart->lessThan($morningEnd) && $iterations < $maxIterations) {
            $slotEnd = $morningStart->copy()->addMinutes($slotDuration);

            if ($slotEnd->lessThanOrEqualTo($morningEnd)) {
                $isAvailable = $this->isAvailableAt(
                    $date,
                    $morningStart->format('H:i'),
                    $slotEnd->format('H:i')
                );

                $slots[] = [
                    'start_time' => $morningStart->format('H:i'),
                    'end_time' => $slotEnd->format('H:i'),
                    'is_available' => $isAvailable,
                ];
            }

            $morningStart->addMinutes($slotDuration);
            $iterations++;
        }

        while ($afternoonStart->lessThan($afternoonEnd) && $iterations < $maxIterations) {
            $slotEnd = $afternoonStart->copy()->addMinutes($slotDuration);

            if ($slotEnd->lessThanOrEqualTo($afternoonEnd)) {
                $isAvailable = $this->isAvailableAt(
                    $date,
                    $afternoonStart->format('H:i'),
                    $slotEnd->format('H:i')
                );

                $slots[] = [
                    'start_time' => $afternoonStart->format('H:i'),
                    'end_time' => $slotEnd->format('H:i'),
                    'is_available' => $isAvailable,
                ];
            }

            $afternoonStart->addMinutes($slotDuration);
            $iterations++;
        }

        return $slots;
    }

    /**
     * Get the next available time slot.
     */
    public function getNextAvailableSlot(
        ?string $afterDate = null,
        int $duration = 60,
        string $dayStart = '09:00',
        string $dayEnd = '17:00'
    ): ?array {
        // Validate inputs
        if ($duration <= 0) {
            return null;
        }

        $startDate = $afterDate ?? now()->format('Y-m-d');
        $checkDate = \Carbon\Carbon::parse($startDate);

        // Check up to 30 days in the future
        for ($i = 0; $i < 30; $i++) {
            $dateString = $checkDate->format('Y-m-d');
            $slots = $this->getAvailableSlots($dateString, $dayStart, $dayEnd, $duration);

            foreach ($slots as $slot) {
                if ($slot['is_available']) {
                    return array_merge($slot, ['date' => $dateString]);
                }
            }

            $checkDate->addDay();
        }

        return null;
    }

    /**
     * Count total scheduled time for a date range.
     */
    public function getTotalScheduledTime(string $startDate, string $endDate): int
    {
        return $this->schedules()
            ->active()
            ->forDateRange($startDate, $endDate)
            ->with('periods')
            ->get()
            ->sum(function ($schedule) {
                return $schedule->periods->sum('duration_minutes');
            });
    }

    /**
     * Check if the model has any schedules.
     */
    public function hasSchedules(): bool
    {
        return $this->schedules()->exists();
    }

    /**
     * Check if the model has any active schedules.
     */
    public function hasActiveSchedules(): bool
    {
        return $this->activeSchedules()->exists();
    }
}
