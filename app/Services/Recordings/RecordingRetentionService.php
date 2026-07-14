<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Per-tenant recording retention: age-out, grace purge, size cap (R1.5 §6.1).
 */
class RecordingRetentionService
{
    private const ARCHIVE_DISK = 'recordings_archive';

    public function __construct(
        private readonly RecordingSchemaService $schema,
        private readonly RecordingPathHelper $paths,
        private readonly RecordingUsageService $usage,
    ) {}

    /**
     * @return array{aged: int, purged: int, size_evicted: int, errors: int}
     */
    public function run(): array
    {
        if (! $this->schema->ensureTable()) {
            return ['aged' => 0, 'purged' => 0, 'size_evicted' => 0, 'errors' => 1];
        }

        $stats = ['aged' => 0, 'purged' => 0, 'size_evicted' => 0, 'errors' => 0];

        $tenants = DB::table('cluster')->get([
            'shortuid', 'recmaxage', 'rec_grace', 'recmaxsize',
        ]);

        foreach ($tenants as $tenant) {
            $shortuid = (string) ($tenant->shortuid ?? '');
            if ($shortuid === '') {
                continue;
            }

            $maxAge = max(0, (int) ($tenant->recmaxage ?? 60));
            $grace = max(0, (int) ($tenant->rec_grace ?? 5));
            $maxSize = max(0, (int) ($tenant->recmaxsize ?? 0));

            $stats['aged'] += $this->ageOutTenant($shortuid, $maxAge);
            $stats['purged'] += $this->purgeDeleteBin($shortuid, $grace);
            if ($maxSize > 0) {
                $stats['size_evicted'] += $this->enforceSizeCap($shortuid, $maxSize);
            }
        }

        try {
            $this->usage->updateAllTenants();
        } catch (\Throwable $e) {
            Log::warning('recused tally failed after retention', ['error' => $e->getMessage()]);
            $stats['errors']++;
        }

        return $stats;
    }

    private function ageOutTenant(string $tenant, int $maxAgeDays): int
    {
        if ($maxAgeDays <= 0) {
            return 0;
        }

        $cutoffEpoch = time() - ($maxAgeDays * 86400);
        $aged = 0;

        $rows = DB::table('recordings')
            ->where('cluster', $tenant)
            ->whereNull('deleted_at')
            ->whereIn('location', [RecordingPathHelper::LOCATION_ARCHIVE, RecordingPathHelper::LOCATION_SPOOL])
            ->where('epoch', '>', 0)
            ->where('epoch', '<', $cutoffEpoch)
            ->get();

        foreach ($rows as $row) {
            if ($this->softDeleteRow($row)) {
                $aged++;
            }
        }

        return $aged;
    }

    private function softDeleteRow(object $row): bool
    {
        $localPath = (string) ($row->local_path ?? '');
        $hasS3 = ! empty($row->s3_key);
        $tenant = (string) $row->cluster;
        $filename = (string) $row->filename;

        if ($localPath !== '' && is_file($localPath)) {
            $archive = Storage::disk(self::ARCHIVE_DISK);
            $deleteRel = $this->paths->deleteBinRelativePath($tenant, $filename);
            $deleteAbs = $archive->path($deleteRel);
            $deleteDir = dirname($deleteAbs);
            if (! is_dir($deleteDir) && ! mkdir($deleteDir, 0755, true) && ! is_dir($deleteDir)) {
                Log::warning('recording delete bin mkdir failed', ['dir' => $deleteDir]);

                return false;
            }
            if (! @rename($localPath, $deleteAbs)) {
                Log::warning('recording age-out move to delete bin failed', ['path' => $localPath]);

                return false;
            }
        }

        $now = gmdate('Y-m-d H:i:s');

        if ($hasS3) {
            // Keep searchable/playable via S3 — do not set deleted_at (DESIGN §6.1).
            DB::table('recordings')->where('id', $row->id)->update([
                'local_path' => null,
                'location' => RecordingPathHelper::LOCATION_S3_ONLY,
                'deleted_at' => null,
                'z_updated' => $now,
                'z_updater' => 'recordings-retention',
            ]);

            return true;
        }

        DB::table('recordings')->where('id', $row->id)->update([
            'deleted_at' => $now,
            'z_updated' => $now,
            'z_updater' => 'recordings-retention',
        ]);

        return true;
    }

    private function purgeDeleteBin(string $tenant, int $graceDays): int
    {
        $archive = Storage::disk(self::ARCHIVE_DISK);
        $deleteDir = "deletes/{$tenant}";
        if (! $archive->exists($deleteDir)) {
            return 0;
        }

        $cutoff = time() - ($graceDays * 86400);
        $purged = 0;

        foreach ($archive->files($deleteDir) as $rel) {
            if (! str_ends_with(strtolower($rel), '.wav')) {
                continue;
            }

            $mtime = @filemtime($archive->path($rel));
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }

            $filename = basename($rel);
            $row = DB::table('recordings')
                ->where('cluster', $tenant)
                ->where('filename', $filename)
                ->first();

            try {
                $archive->delete($rel);
            } catch (\Throwable) {
                continue;
            }

            if ($row !== null && empty($row->s3_key)) {
                DB::table('recordings')->where('id', $row->id)->delete();
            }

            $purged++;
        }

        return $purged;
    }

    private function enforceSizeCap(string $tenant, int $maxBytes): int
    {
        $rows = DB::table('recordings')
            ->where('cluster', $tenant)
            ->whereNull('deleted_at')
            ->whereNotNull('local_path')
            ->where('local_path', '!=', '')
            ->orderBy('epoch')
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $path = (string) $row->local_path;
            if ($path !== '' && is_file($path)) {
                $total += filesize($path) ?: 0;
            } elseif ((int) ($row->filesize ?? 0) > 0) {
                $total += (int) $row->filesize;
            }
        }

        if ($total <= $maxBytes) {
            return 0;
        }

        $evicted = 0;

        foreach ($rows as $row) {
            if ($total <= $maxBytes) {
                break;
            }

            $path = (string) $row->local_path;
            $size = 0;
            if ($path !== '' && is_file($path)) {
                $size = filesize($path) ?: 0;
            } else {
                $size = (int) ($row->filesize ?? 0);
            }

            if ($this->softDeleteRow($row)) {
                $total -= $size;
                $evicted++;
            }
        }

        return $evicted;
    }
}
