<?php

namespace Tests\Unit;

use App\Support\ScheduleModes;
use PHPUnit\Framework\TestCase;

class ScheduleModesTest extends TestCase
{
    public function test_normalize_empty_defaults_to_open(): void
    {
        $this->assertSame('open', ScheduleModes::normalize(null));
        $this->assertSame('open', ScheduleModes::normalize(''));
        $this->assertSame('closed', ScheduleModes::normalize(null, 'closed'));
    }

    public function test_normalize_lowercases(): void
    {
        $this->assertSame('lunch', ScheduleModes::normalize('Lunch'));
        $this->assertSame('closed', ScheduleModes::normalize('  CLOSED  '));
    }

    public function test_is_valid_common_modes(): void
    {
        foreach (['open', 'closed', 'lunch', 'night', 'break'] as $m) {
            $this->assertTrue(ScheduleModes::isValid($m), $m);
        }
    }

    public function test_is_valid_custom_and_reject(): void
    {
        $this->assertTrue(ScheduleModes::isValid('evening-1'));
        $this->assertTrue(ScheduleModes::isValid('a'));
        $this->assertFalse(ScheduleModes::isValid('Bad Mode'));
        $this->assertFalse(ScheduleModes::isValid('1starts-digit'));
        $this->assertFalse(ScheduleModes::isValid('has space'));
        $this->assertFalse(ScheduleModes::isValid(''));
        $this->assertTrue(ScheduleModes::isValid('', true));
    }

    public function test_mode_regex_constant_matches_validation(): void
    {
        $this->assertMatchesRegularExpression(ScheduleModes::MODE_REGEX, 'open');
        $this->assertDoesNotMatchRegularExpression(ScheduleModes::MODE_REGEX, 'Open');
    }
}
