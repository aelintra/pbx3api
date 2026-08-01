<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Node site timezone for CDR presentation / day buckets (Network OS TZ).
 * CDR HoR strings are UTC; convert bounds and labels via this IANA id.
 *
 * @see pbx3/workingdocs/CDR_TIMEZONE_POLICY.md
 */
class SiteTimezone
{
    /**
     * IANA timezone id (e.g. America/New_York). Falls back to UTC.
     */
    public static function id(): string
    {
        $override = config('pbx3_cdr.site_timezone');
        if (is_string($override) && trim($override) !== '') {
            return self::normalize(trim($override));
        }

        $path = (string) config('pbx3_cdr.timezone_file', '/etc/timezone');
        if (is_readable($path)) {
            $raw = trim((string) @file_get_contents($path));
            if ($raw !== '') {
                return self::normalize($raw);
            }
        }

        return 'UTC';
    }

    public static function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone(self::id());
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Start of "today" in site TZ, as a UTC wall string for comparing to CDR calldate.
     */
    public static function todayStartUtc(): string
    {
        $site = new DateTimeImmutable('today', self::zone());

        return $site->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Calendar day YYYY-MM-DD in site TZ → UTC inclusive bound for SQL.
     */
    public static function calendarDayBoundUtc(string $ymd, bool $endOfDay): string
    {
        $ymd = trim($ymd);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return $ymd;
        }

        $local = new DateTimeImmutable(
            $ymd.($endOfDay ? ' 23:59:59' : ' 00:00:00'),
            self::zone()
        );

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function normalize(string $id): string
    {
        try {
            new DateTimeZone($id);

            return $id;
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
