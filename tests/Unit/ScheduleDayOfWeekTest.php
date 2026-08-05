<?php

namespace Tests\Unit;

use App\Support\ScheduleDayOfWeek;
use PHPUnit\Framework\TestCase;

class ScheduleDayOfWeekTest extends TestCase
{
    public function test_star_and_singles(): void
    {
        $this->assertTrue(ScheduleDayOfWeek::isValid('*'));
        $this->assertTrue(ScheduleDayOfWeek::isValid('mon'));
        $this->assertTrue(ScheduleDayOfWeek::isValid('SUN'));
        $this->assertSame('sun', ScheduleDayOfWeek::normalize('SUN'));
    }

    public function test_forward_ranges(): void
    {
        $this->assertTrue(ScheduleDayOfWeek::isValid('mon-fri'));
        $this->assertTrue(ScheduleDayOfWeek::isValid('mon-thu'));
        $this->assertTrue(ScheduleDayOfWeek::isValid('tue-fri'));
        $this->assertTrue(ScheduleDayOfWeek::isValid('sat-sun'));
    }

    public function test_rejects_wrap_and_same(): void
    {
        $this->assertFalse(ScheduleDayOfWeek::isValid('tue-mon'));
        $this->assertFalse(ScheduleDayOfWeek::isValid('fri-mon'));
        $this->assertFalse(ScheduleDayOfWeek::isValid('mon-mon'));
        $this->assertFalse(ScheduleDayOfWeek::isValid('foo-bar'));
        $this->assertFalse(ScheduleDayOfWeek::isValid('mon,fri'));
    }
}
