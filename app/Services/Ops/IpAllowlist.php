<?php

namespace App\Services\Ops;

/** IPv4 / CIDR membership for Fail2ban ignoreip-style allowlists. */
final class IpAllowlist
{
    /**
     * @return list<string>
     */
    public static function parseIgnoreipLine(string $line): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($line)) ?: [] as $tok) {
            $tok = trim($tok);
            if ($tok !== '' && $tok !== 'ignoreip' && ! str_starts_with($tok, '=')) {
                $out[] = $tok;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function fromJailFile(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }
        $text = (string) file_get_contents($path);
        if (preg_match('/^\s*ignoreip\s*=\s*(.+)$/mi', $text, $m) !== 1) {
            return [];
        }

        return self::parseIgnoreipLine($m[1]);
    }

    /**
     * @param  list<string>  $allowlist
     */
    public static function contains(array $allowlist, string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach ($allowlist as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                [$net, $bits] = explode('/', $entry, 2);
                if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                $bits = (int) $bits;
                if ($bits < 0 || $bits > 32) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
                $netLong = ip2long($net);
                if ($netLong === false) {
                    continue;
                }
                if (($ipLong & $mask) === ($netLong & $mask)) {
                    return true;
                }
            } elseif ($entry === $ip) {
                return true;
            }
        }

        return false;
    }
}
