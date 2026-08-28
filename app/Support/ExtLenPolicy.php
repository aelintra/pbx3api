<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tenant extension length / digit-plan length namespaces (#4c).
 * Spec: TENANT_SHORT_DIAL_REQUIREMENTS.md §3.8 / Q15.
 */
final class ExtLenPolicy
{
    public const MIN = 2;

    public const MAX = 5;

    public const DEFAULT = 3;

    /**
     * OutRoute dialplan floor (SARK-aligned): min match must be **greater than 2**
     * (i.e. at least 3). Exact extension matches always outrank patterns in Asterisk;
     * operators who put extensions in 1xx accept the collision.
     */
    public const OUTROUTE_MIN_MATCH = 3;

    /** UK seed that satisfies OUTROUTE_MIN_MATCH (L7a). */
    public const UK_SEED_DIALPLAN = '_0XXX. _00XX.';

    public static function normalize(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT;
        }
        if (! is_numeric($raw)) {
            return self::DEFAULT;
        }
        $n = (int) $raw;
        if ($n < self::MIN || $n > self::MAX) {
            return self::DEFAULT;
        }

        return $n;
    }

    public static function isValidExtLen(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return true; // nullable → default on write
        }
        if (! is_numeric($raw)) {
            return false;
        }
        $n = (int) $raw;

        return $n >= self::MIN && $n <= self::MAX;
    }

    public static function extLenValidationMessage(): string
    {
        return 'Extension length must be an integer from '.self::MIN.' to '.self::MAX.' (default '.self::DEFAULT.').';
    }

    /**
     * Extension pkey must be exactly ext_len digits (no mixed lengths in a tenant).
     */
    public static function isValidExtensionPkey(mixed $pkey, int $extLen): bool
    {
        $extLen = self::normalize($extLen);
        $s = trim((string) $pkey);

        return (bool) preg_match('/^\d{'.$extLen.'}$/', $s);
    }

    public static function extensionPkeyValidationMessage(int $extLen): string
    {
        $extLen = self::normalize($extLen);

        return "Extension number must be exactly {$extLen} digits (tenant extension length).";
    }

    /**
     * Minimum dialled length matched by one Asterisk pattern token.
     * Leading `_` optional. `X`/`Z`/`N`/[classes] = 1; `.` = 1 (one-or-more); `!` = 0.
     * Returns null if the token is empty or not a recognisable pattern/literal digit string.
     */
    public static function minMatchLength(string $token): ?int
    {
        $p = trim($token);
        if ($p === '') {
            return null;
        }
        if (isset($p[0]) && $p[0] === '_') {
            $p = substr($p, 1);
        }
        if ($p === '') {
            return null;
        }

        $min = 0;
        $len = strlen($p);
        for ($i = 0; $i < $len; $i++) {
            $c = $p[$i];
            if ($c === 'X' || $c === 'Z' || $c === 'N' || ctype_digit($c)) {
                $min++;
                continue;
            }
            if ($c === '.') {
                $min++; // one or more
                continue;
            }
            if ($c === '!') {
                continue; // zero or more
            }
            if ($c === '[') {
                $end = strpos($p, ']', $i);
                if ($end === false) {
                    return null;
                }
                $min++;
                $i = $end;
                continue;
            }

            // Unknown pattern char — treat as literal (letter etc.)
            $min++;
        }

        return $min;
    }

    /**
     * Validate space-separated OutRoute dialplan string.
     * Every non-empty token must have min match length ≥ OUTROUTE_MIN_MATCH (3).
     * SARK rule: anything larger than two chars. $extLen retained for call-site
     * compatibility; not used for the floor check.
     *
     * @return string|null error message, or null if OK
     */
    public static function dialplanError(?string $dialplan, int $extLen = self::DEFAULT): ?string
    {
        unset($extLen); // call-site compat; OutRoute floor is not tenant ext_len
        $raw = trim((string) $dialplan);
        if ($raw === '') {
            return null; // emptiness enforced elsewhere when required
        }
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
        $floor = self::OUTROUTE_MIN_MATCH;
        foreach (explode(' ', $raw) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $min = self::minMatchLength($token);
            if ($min === null) {
                return "Invalid dialplan pattern: {$token}";
            }
            if ($min < $floor) {
                return "Dialplan pattern {$token} minimum match length ({$min}) must be at least {$floor}.";
            }
        }

        return null;
    }

    /**
     * Short-dial total length (prefix + dest rem) must exceed caller ext_len.
     */
    public static function shortDialLengthOk(int $prefixWidth, int $destExtLen, int $callerExtLen): bool
    {
        $destExtLen = self::normalize($destExtLen);
        $callerExtLen = self::normalize($callerExtLen);

        return ($prefixWidth + $destExtLen) > $callerExtLen;
    }

    public static function shortDialLengthValidationMessage(int $prefixWidth, int $destExtLen, int $callerExtLen): string
    {
        $destExtLen = self::normalize($destExtLen);
        $callerExtLen = self::normalize($callerExtLen);
        $total = $prefixWidth + $destExtLen;

        return "Dial prefix + destination extension length ({$total}) must be greater than calling tenant extension length ({$callerExtLen}).";
    }

    /** Asterisk remainder mask: XXX for ext_len=3. */
    public static function remainderMask(int $extLen): string
    {
        return str_repeat('X', self::normalize($extLen));
    }

    /**
     * Resolve ext_len for a cluster identifier (pkey / shortuid / id).
     */
    public static function forClusterIdentifier(mixed $identifier): int
    {
        $id = trim((string) $identifier);
        if ($id === '') {
            return self::DEFAULT;
        }
        try {
            $row = DB::table('cluster')
                ->where('shortuid', $id)
                ->orWhere('pkey', $id)
                ->orWhere('id', $id)
                ->first(['ext_len']);
            if ($row) {
                return self::normalize($row->ext_len ?? null);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return self::DEFAULT;
    }

    /**
     * Destination ext_len for a dial prefix: local cluster by shortuid/FQDN, else $fallback.
     */
    public static function forDialTarget(?string $targetCluster, ?string $targetFqdn, int $fallback = self::DEFAULT): int
    {
        $fallback = self::normalize($fallback);
        $pin = trim((string) $targetCluster);
        if ($pin !== '') {
            try {
                $row = DB::table('cluster')
                    ->where('shortuid', $pin)
                    ->orWhere('pkey', $pin)
                    ->orWhere('id', $pin)
                    ->first(['ext_len']);
                if ($row) {
                    return self::normalize($row->ext_len ?? null);
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        $fqdn = strtolower(trim((string) $targetFqdn));
        if ($fqdn !== '') {
            try {
                $row = DB::table('cluster')
                    ->whereRaw('LOWER(TRIM(fqdn)) = ?', [$fqdn])
                    ->first(['ext_len']);
                if ($row) {
                    return self::normalize($row->ext_len ?? null);
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return $fallback;
    }
}
