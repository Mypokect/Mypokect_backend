<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeZone;

/**
 * Service for handling recurring reminders logic.
 * Handles monthly recurrence with edge cases like end-of-month dates.
 */
class RecurrenceService
{
    /**
     * Calculate the next occurrence date for a monthly recurring reminder.
     * 
     * Rule: If the target day doesn't exist in the month (e.g., 31st in February),
     * use the last day of that month.
     * 
     * @param Carbon $currentDate The current occurrence date
     * @param int $dayOfMonth Target day of the month (1-31)
     * @param string $timezone User's timezone
     * @return Carbon Next occurrence date in UTC
     */
    public function calculateNextMonthlyOccurrence(Carbon $currentDate, int $dayOfMonth, string $timezone): Carbon
    {
        $tz = new DateTimeZone($timezone);
        
        // Convert to user's timezone for calculation
        $localDate = $currentDate->copy()->setTimezone($tz);
        
        // Move to next month
        $nextMonth = $localDate->copy()->addMonth();
        
        // Get the last day of that month
        $lastDayOfMonth = $nextMonth->daysInMonth;
        
        // Use the target day or last day if target doesn't exist
        $targetDay = min($dayOfMonth, $lastDayOfMonth);
        
        // Set the day
        $nextMonth->day($targetDay);
        
        // Keep the same time as the original
        $nextMonth->setTime(
            $localDate->hour,
            $localDate->minute,
            $localDate->second
        );
        
        // Convert back to UTC
        return $nextMonth->setTimezone('UTC');
    }

    /**
     * Generate multiple future occurrences for a monthly recurring reminder.
     * 
     * @param Carbon $startDate Starting date
     * @param int $dayOfMonth Target day of month
     * @param string $timezone User's timezone
     * @param int $count Number of occurrences to generate
     * @return array Array of Carbon dates in UTC
     */
    public function generateMonthlyOccurrences(Carbon $startDate, int $dayOfMonth, string $timezone, int $count = 12): array
    {
        $occurrences = [$startDate->copy()];
        $currentDate = $startDate->copy();
        
        for ($i = 1; $i < $count; $i++) {
            $currentDate = $this->calculateNextMonthlyOccurrence($currentDate, $dayOfMonth, $timezone);
            $occurrences[] = $currentDate->copy();
        }
        
        return $occurrences;
    }

    /**
     * Get occurrences within a date range for a monthly recurring reminder.
     * 
     * @param Carbon $reminderStartDate Original reminder due date
     * @param int $dayOfMonth Target day of month
     * @param string $timezone User's timezone
     * @param Carbon $rangeStart Start of range
     * @param Carbon $rangeEnd End of range
     * @return array Array of Carbon dates in UTC
     */
    public function getOccurrencesInRange(
        Carbon $reminderStartDate,
        int $dayOfMonth,
        string $timezone,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $occurrences = [];
        $currentDate = $reminderStartDate->copy();
        
        // If reminder start is after range end, return empty
        if ($currentDate->isAfter($rangeEnd)) {
            return [];
        }
        
        // If reminder start is before range start, fast-forward
        while ($currentDate->isBefore($rangeStart)) {
            $currentDate = $this->calculateNextMonthlyOccurrence($currentDate, $dayOfMonth, $timezone);
        }
        
        // Collect occurrences within range
        while ($currentDate->isBefore($rangeEnd) || $currentDate->equalTo($rangeEnd)) {
            $occurrences[] = $currentDate->copy();
            $currentDate = $this->calculateNextMonthlyOccurrence($currentDate, $dayOfMonth, $timezone);
        }
        
        return $occurrences;
    }

    /**
     * Validate if a day of month is valid for a given month/year.
     * 
     * @param int $dayOfMonth Day to validate
     * @param int $month Month (1-12)
     * @param int $year Year
     * @return bool
     */
    public function isValidDayForMonth(int $dayOfMonth, int $month, int $year): bool
    {
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            return false;
        }
        
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        
        return $dayOfMonth <= $daysInMonth;
    }

    /**
     * Get the adjusted day for a month (last day if target doesn't exist).
     * 
     * @param int $targetDay Target day of month
     * @param int $month Month (1-12)
     * @param int $year Year
     * @return int Adjusted day
     */
    public function getAdjustedDayForMonth(int $targetDay, int $month, int $year): int
    {
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        
        return min($targetDay, $daysInMonth);
    }
}
