<?php

namespace App\Services\Ops;

/**
 * Off-hours window matching for velocity WP1.
 *
 * Spec: FLEET_TOLL_FRAUD_VELOCITY_IMPLEMENTATION_PLAN.md WP1 —
 * clock = node TZ / policy off_hours.tz; dow 0=Sun…6=Sat (PHP date('w')).
 * Windows with start > end cross midnight (e.g. 18:00→06:00).
 */
final class VelocityOffHoursClock
{
    /**
     * @param  list<array{dow?:list<int|string>, start?:string, end?:string}>  $windows
     */
    public function isInWindow(\DateTimeInterface $when, string $tz, array $windows): bool
    {
        if ($windows === []) {
            return false;
        }

        try {
            $tzObj = new \DateTimeZone($tz !== '' ? $tz : 'UTC');
        } catch (\Throwable) {
            $tzObj = new \DateTimeZone('UTC');
        }

        $local = \DateTimeImmutable::createFromInterface($when)->setTimezone($tzObj);
        $dow = (int) $local->format('w');
        $minutes = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        foreach ($windows as $win) {
            if (! is_array($win)) {
                continue;
            }
            $dows = [];
            foreach ($win['dow'] ?? [] as $d) {
                $dows[] = (int) $d;
            }
            if ($dows !== [] && ! in_array($dow, $dows, true)) {
                continue;
            }
            $start = $this->parseHm((string) ($win['start'] ?? '00:00'));
            $end = $this->parseHm((string) ($win['end'] ?? '23:59'));
            if ($start === null || $end === null) {
                continue;
            }
            if ($start === $end) {
                // Full day for listed dows.
                return true;
            }
            if ($start < $end) {
                if ($minutes >= $start && $minutes < $end) {
                    return true;
                }
            } else {
                // Crosses midnight: in if >= start OR < end.
                if ($minutes >= $start || $minutes < $end) {
                    return true;
                }
            }
        }

        return false;
    }

    public function parseCalldate(string $calldate, string $assumeTz = 'UTC'): ?\DateTimeImmutable
    {
        $calldate = trim($calldate);
        if ($calldate === '') {
            return null;
        }
        try {
            $tz = new \DateTimeZone($assumeTz !== '' ? $assumeTz : 'UTC');
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $calldate, $tz);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt;
        }
        try {
            return new \DateTimeImmutable($calldate, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseHm(string $hm): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }

        return $h * 60 + $i;
    }
}
