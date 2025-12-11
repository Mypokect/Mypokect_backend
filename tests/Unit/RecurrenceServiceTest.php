<?php

namespace Tests\Unit;

use App\Services\RecurrenceService;
use Carbon\Carbon;
use Tests\TestCase;

class RecurrenceServiceTest extends TestCase
{
    protected RecurrenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecurrenceService();
    }

    /** @test */
    public function it_calculates_next_monthly_occurrence_for_regular_day()
    {
        $currentDate = Carbon::parse('2025-01-15 10:00:00', 'UTC');
        $dayOfMonth = 15;
        $timezone = 'America/Bogota';

        $nextDate = $this->service->calculateNextMonthlyOccurrence($currentDate, $dayOfMonth, $timezone);

        $this->assertEquals('2025-02-15', $nextDate->format('Y-m-d'));
    }

    /** @test */
    public function it_handles_end_of_month_edge_case_february()
    {
        // January 31st, expecting February 28th (non-leap year)
        $currentDate = Carbon::parse('2025-01-31 10:00:00', 'UTC');
        $dayOfMonth = 31;
        $timezone = 'America/Bogota';

        $nextDate = $this->service->calculateNextMonthlyOccurrence($currentDate, $dayOfMonth, $timezone);

        $this->assertEquals('2025-02-28', $nextDate->format('Y-m-d'));
    }

    /** @test */
    public function it_handles_leap_year_february()
    {
        // January 31st 2024 (leap year), expecting February 29th
        $currentDate = Carbon::parse('2024-01-31 10:00:00', 'UTC');
        $dayOfMonth = 31;
        $timezone = 'America/Bogota';

        $nextDate = $this->service->calculateNextMonthlyOccurrence($currentDate, $dayOfMonth, $timezone);

        $this->assertEquals('2024-02-29', $nextDate->format('Y-m-d'));
    }

    /** @test */
    public function it_handles_30_day_months()
    {
        // January 31st, expecting April 30th (after March 31st)
        $marchDate = Carbon::parse('2025-03-31 10:00:00', 'UTC');
        $dayOfMonth = 31;
        $timezone = 'America/Bogota';

        $aprilDate = $this->service->calculateNextMonthlyOccurrence($marchDate, $dayOfMonth, $timezone);

        $this->assertEquals('2025-04-30', $aprilDate->format('Y-m-d'));
    }

    /** @test */
    public function it_generates_multiple_occurrences()
    {
        $startDate = Carbon::parse('2025-01-15 10:00:00', 'UTC');
        $dayOfMonth = 15;
        $timezone = 'America/Bogota';

        $occurrences = $this->service->generateMonthlyOccurrences($startDate, $dayOfMonth, $timezone, 3);

        $this->assertCount(3, $occurrences);
        $this->assertEquals('2025-01-15', $occurrences[0]->format('Y-m-d'));
        $this->assertEquals('2025-02-15', $occurrences[1]->format('Y-m-d'));
        $this->assertEquals('2025-03-15', $occurrences[2]->format('Y-m-d'));
    }

    /** @test */
    public function it_gets_occurrences_in_range()
    {
        $reminderStart = Carbon::parse('2025-01-15 10:00:00', 'UTC');
        $dayOfMonth = 15;
        $timezone = 'America/Bogota';
        $rangeStart = Carbon::parse('2025-02-01', 'UTC');
        $rangeEnd = Carbon::parse('2025-05-31', 'UTC');

        $occurrences = $this->service->getOccurrencesInRange(
            $reminderStart,
            $dayOfMonth,
            $timezone,
            $rangeStart,
            $rangeEnd
        );

        $this->assertCount(4, $occurrences); // Feb, Mar, Apr, May
        $this->assertEquals('2025-02-15', $occurrences[0]->format('Y-m-d'));
        $this->assertEquals('2025-05-15', $occurrences[3]->format('Y-m-d'));
    }

    /** @test */
    public function it_validates_day_for_month()
    {
        $this->assertTrue($this->service->isValidDayForMonth(15, 2, 2025));
        $this->assertFalse($this->service->isValidDayForMonth(30, 2, 2025));
        $this->assertTrue($this->service->isValidDayForMonth(29, 2, 2024)); // Leap year
        $this->assertFalse($this->service->isValidDayForMonth(29, 2, 2025)); // Non-leap
    }

    /** @test */
    public function it_adjusts_day_for_month()
    {
        $this->assertEquals(28, $this->service->getAdjustedDayForMonth(31, 2, 2025));
        $this->assertEquals(29, $this->service->getAdjustedDayForMonth(31, 2, 2024));
        $this->assertEquals(30, $this->service->getAdjustedDayForMonth(31, 4, 2025));
        $this->assertEquals(31, $this->service->getAdjustedDayForMonth(31, 1, 2025));
    }
}
