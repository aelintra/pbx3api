<?php

namespace App\Support;

/**
 * Day-parts mode strings (plain, lowercased). Used by day timers, profiles, holidays.
 */
final class ScheduleModes
{
    public const MODE_REGEX = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** Hint list only — custom modes allowed when they match MODE_REGEX. */
    public const COMMON = ['open', 'closed', 'lunch', 'night', 'break'];

    public static function normalize(?string $mode, string $default = 'open'): string
    {
        $m = strtolower(trim((string) $mode));
        if ($m === '') {
            return $default;
        }

        return $m;
    }

    public static function isValid(?string $mode, bool $allowEmpty = false): bool
    {
        $m = strtolower(trim((string) $mode));
        if ($m === '') {
            return $allowEmpty;
        }

        return (bool) preg_match(self::MODE_REGEX, $m);
    }

    /**
     * Laravel validation rule string for a mode field.
     */
    public static function validationRule(bool $nullable = true): string
    {
        $base = 'regex:'.self::MODE_REGEX;
        if ($nullable) {
            return 'nullable|string|'.$base;
        }

        return 'required|string|'.$base;
    }
}
