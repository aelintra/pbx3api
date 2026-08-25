<?php

namespace App\Support;

/**
 * Pure validation for UFW allow-list rows (FirewallController).
 * Spec: pbx3/workingdocs/UFW_SHOREWALL_MIGRATION.md §7 / F5 / F10.
 */
final class FirewallAllowRule
{
    public static function isValidFrom(string $from): bool
    {
        if ($from === 'any') {
            return true;
        }
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(\/[0-9]{1,2})?$/', $from)) {
            return true;
        }
        $addr = $from;
        if (str_contains($from, '/')) {
            [$addr, $prefix] = explode('/', $from, 2);
            if (!ctype_digit($prefix)) {
                return false;
            }
            $p = (int) $prefix;
            if ($p < 0 || $p > 128) {
                return false;
            }
        }

        return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * @return string|null error message or null if OK
     */
    public static function validateShape(string $proto, string $port, string $from): ?string
    {
        if (!self::isValidFrom($from)) {
            return "from must be 'any', an IPv4/CIDR, or an IPv6/CIDR (got: {$from})";
        }
        if ($proto === 'icmp') {
            return null;
        }
        if ($proto === 'all') {
            if ($port !== '' && strtoupper($port) !== 'N/A') {
                return 'proto=all must not set a port';
            }

            return null;
        }
        if ($port === '' || !preg_match('/^[0-9]+(:[0-9]+)?$/', $port)) {
            return "port must be a number or range like 10000:20000 (got: {$port})";
        }

        return null;
    }

    public static function looksLikeLegacyShorewallLines(array $rules): bool
    {
        if ($rules === []) {
            return false;
        }
        $first = $rules[0];
        if (!is_string($first)) {
            return false;
        }

        return (bool) preg_match('/^(ACCEPT|DROP|REJECT|INLINE)\b/i', trim($first));
    }
}
