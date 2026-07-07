<?php

namespace App\Services\Recordings;

/**
 * Path helpers for spool → archive layout ({tenant}/{yyyy}/{mm}/{dd}/{filename}.wav).
 */
class RecordingPathHelper
{
    public const LOCATION_SPOOL = 'spool';

    public const LOCATION_ARCHIVE = 'archive';

    public const LOCATION_S3 = 's3';

    public const LOCATION_S3_ONLY = 's3_only';

    public function archiveRelativePath(string $tenant, int $epoch, string $filename): string
    {
        $ts = $epoch > 0 ? $epoch : time();
        $date = gmdate('Y/m/d', $ts);

        return "{$tenant}/{$date}/{$filename}";
    }

    public function deleteBinRelativePath(string $tenant, string $filename): string
    {
        return "deletes/{$tenant}/{$filename}";
    }

    /** Legacy R1 id: base64url of spool-relative {tenant}/{filename}.wav */
    public function legacyIdFromSpoolPath(string $tenant, string $filename): string
    {
        $rel = "{$tenant}/{$filename}";

        return rtrim(strtr(base64_encode($rel), '+/', '-_'), '=');
    }

    public function decodeLegacyId(string $id): ?string
    {
        $decoded = base64_decode(strtr($id, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        if (str_contains($decoded, "\0") || str_contains($decoded, '..') || str_starts_with($decoded, '/')) {
            return null;
        }
        if (preg_match('#^[^/]+/[^/]+\.wav$#i', $decoded) !== 1) {
            return null;
        }

        return $decoded;
    }

    public function isKsuidId(string $id): bool
    {
        return strlen($id) === 27 && preg_match('/^[0-9A-Za-z]{27}$/', $id) === 1;
    }
}
