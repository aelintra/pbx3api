<?php

namespace App\Support;

/**
 * Day-timer dayofweek: *, single dow, or forward range (mon-fri). No wrap (tue-mon).
 * Mirrors pbx3_dayofweek_* in pbx3-schedule.php.
 */
final class ScheduleDayOfWeek
{
    public const ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public static function normalize(?string $spec): string
    {
        $s = strtolower(trim((string) $spec));

        return $s === '' ? '*' : $s;
    }

    public static function isValid(?string $spec): bool
    {
        $s = self::normalize($spec);
        if ($s === '*') {
            return true;
        }
        $idx = array_flip(self::ORDER);
        if (isset($idx[$s])) {
            return true;
        }
        if (! preg_match('/^([a-z]{3})-([a-z]{3})$/', $s, $m)) {
            return false;
        }
        $a = $m[1];
        $b = $m[2];
        if (! isset($idx[$a], $idx[$b])) {
            return false;
        }
        // Forward only; reject wrap and same-day range.
        return $idx[$a] < $idx[$b];
    }

    public static function validationMessage(): string
    {
        return 'Day of week must be *, a day (mon…sun), or a forward range (e.g. mon-fri, tue-fri). Wrap-around (tue-mon) is not allowed.';
    }
}
