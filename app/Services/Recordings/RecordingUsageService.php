<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Update cluster.recused from spool + archive bytes per tenant (replaces manageRecs.php).
 */
class RecordingUsageService
{
    private const SPOOL_DISK = 'recordings';

    private const ARCHIVE_DISK = 'recordings_archive';

    public function updateAllTenants(): int
    {
        $updated = 0;
        $tenants = DB::table('cluster')->pluck('shortuid');

        foreach ($tenants as $shortuid) {
            if ($shortuid === null || $shortuid === '') {
                continue;
            }
            $bytes = $this->bytesForTenant((string) $shortuid);
            DB::table('cluster')->where('shortuid', $shortuid)->update([
                'recused' => (string) $bytes,
            ]);
            $updated++;
        }

        return $updated;
    }

    public function bytesForTenant(string $tenant): int
    {
        $bytes = 0;
        $spool = Storage::disk(self::SPOOL_DISK);
        $tenantDir = $tenant;
        if ($spool->exists($tenantDir)) {
            foreach ($spool->files($tenantDir) as $rel) {
                if (str_ends_with(strtolower($rel), '.wav')) {
                    try {
                        $bytes += (int) $spool->size($rel);
                    } catch (\Throwable) {
                        // skip
                    }
                }
            }
        }

        $archive = Storage::disk(self::ARCHIVE_DISK);
        $this->sumArchiveTree($archive, $tenant, $bytes);
        $this->sumArchiveTree($archive, "deletes/{$tenant}", $bytes);

        return $bytes;
    }

    private function sumArchiveTree(
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        string $prefix,
        int &$bytes,
    ): void {
        if (! $disk->exists($prefix)) {
            return;
        }

        foreach ($disk->files($prefix) as $rel) {
            if (str_ends_with(strtolower($rel), '.wav')) {
                try {
                    $bytes += (int) $disk->size($rel);
                } catch (\Throwable) {
                    // skip
                }
            }
        }

        foreach ($disk->directories($prefix) as $subdir) {
            $this->sumArchiveTree($disk, $subdir, $bytes);
        }
    }
}
