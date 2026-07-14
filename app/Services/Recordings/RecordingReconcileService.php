<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Recordings catalog sweeper (R1.5 backfill + S7.10 drift).
 *
 * 1. Insert missing SQLite rows from the local archive tree.
 * 2. Repair SQLite ↔ local disk ↔ S3:
 *    - stale local_path → s3_only (if s3_key) or soft-delete
 *    - missing S3 object → clear s3_key or delete row
 *    - s3_only but file returned → restore archive + local_path
 */
class RecordingReconcileService
{
    private const ARCHIVE_DISK = 'recordings_archive';

    public function __construct(
        private readonly RecordingSchemaService $schema,
        private readonly RecordingFilenameParser $parser,
        private readonly RecordingPathHelper $paths,
        private readonly GatekeeperRecordingsClient $gatekeeper,
    ) {}

    /**
     * @return array{inserted: int, skipped: int, repaired: int, removed: int, errors: int}
     */
    public function run(?string $tenantFilter = null, bool $drift = true): array
    {
        if (! $this->schema->ensureTable()) {
            return ['inserted' => 0, 'skipped' => 0, 'repaired' => 0, 'removed' => 0, 'errors' => 1];
        }

        $stats = $this->backfillFromArchive($tenantFilter);

        if ($drift) {
            $driftStats = $this->repairDrift($tenantFilter);
            $stats['repaired'] = $driftStats['repaired'];
            $stats['removed'] = $driftStats['removed'];
            $stats['errors'] += $driftStats['errors'];
        } else {
            $stats['repaired'] = 0;
            $stats['removed'] = 0;
        }

        return $stats;
    }

    /**
     * @return array{inserted: int, skipped: int, repaired: int, removed: int, errors: int}
     */
    private function backfillFromArchive(?string $tenantFilter): array
    {
        $archive = Storage::disk(self::ARCHIVE_DISK);
        $stats = ['inserted' => 0, 'skipped' => 0, 'repaired' => 0, 'removed' => 0, 'errors' => 0];

        foreach ($archive->directories() as $tenantDir) {
            $tenant = basename($tenantDir);
            if ($tenant === 'deletes') {
                continue;
            }
            if ($tenantFilter !== null && $tenant !== $tenantFilter) {
                continue;
            }

            foreach ($archive->allFiles($tenantDir) as $rel) {
                if (! str_ends_with(strtolower($rel), '.wav')) {
                    continue;
                }
                if (str_starts_with($rel, 'deletes/')) {
                    continue;
                }

                $filename = basename($rel);
                $exists = DB::table('recordings')
                    ->where('cluster', $tenant)
                    ->where('filename', $filename)
                    ->exists();

                if ($exists) {
                    $stats['skipped']++;

                    continue;
                }

                $parsed = $this->parser->parse($tenant, $filename);
                $abs = $archive->path($rel);
                $filesize = is_file($abs) ? (filesize($abs) ?: 0) : 0;
                $now = gmdate('Y-m-d H:i:s');

                DB::table('recordings')->insert([
                    'id' => generate_ksuid(),
                    'cluster' => $tenant,
                    'epoch' => (int) $parsed['epoch'],
                    'callerid' => $parsed['callerid'],
                    'dnid' => $parsed['dnid'],
                    'queue' => $parsed['queue'],
                    'extension' => $parsed['extension'],
                    'filename' => $filename,
                    'local_path' => $abs,
                    's3_key' => null,
                    'location' => RecordingPathHelper::LOCATION_ARCHIVE,
                    'filesize' => $filesize,
                    'deleted_at' => null,
                    'z_created' => $now,
                    'z_updated' => $now,
                    'z_updater' => 'recordings-reconcile',
                ]);

                $stats['inserted']++;
            }
        }

        return $stats;
    }

    /**
     * @return array{repaired: int, removed: int, errors: int}
     */
    private function repairDrift(?string $tenantFilter): array
    {
        $stats = ['repaired' => 0, 'removed' => 0, 'errors' => 0];
        $checkS3 = $this->gatekeeper->isConfigured();

        $query = DB::table('recordings')->orderBy('epoch');
        if ($tenantFilter !== null && $tenantFilter !== '') {
            $query->where('cluster', $tenantFilter);
        }

        foreach ($query->cursor() as $row) {
            try {
                $result = $this->repairRow($row, $checkS3);
                if ($result === 'repaired') {
                    $stats['repaired']++;
                } elseif ($result === 'removed') {
                    $stats['removed']++;
                }
            } catch (\Throwable $e) {
                Log::warning('recording drift repair failed', [
                    'id' => $row->id ?? null,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * @return 'ok'|'repaired'|'removed'
     */
    private function repairRow(object $row, bool $checkS3): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $tenant = (string) $row->cluster;
        $filename = (string) $row->filename;
        $localPath = (string) ($row->local_path ?? '');
        $s3Key = (string) ($row->s3_key ?? '');
        $location = (string) ($row->location ?? RecordingPathHelper::LOCATION_ARCHIVE);
        $hasLocal = $localPath !== '' && is_file($localPath);
        $hasS3Key = $s3Key !== '';

        // Rediscover local file when path blank/stale but archive still holds it.
        if (! $hasLocal) {
            $found = $this->findArchiveAbsolute($tenant, $filename, (int) ($row->epoch ?? 0));
            if ($found !== null) {
                $localPath = $found;
                $hasLocal = true;
                DB::table('recordings')->where('id', $row->id)->update([
                    'local_path' => $localPath,
                    'location' => RecordingPathHelper::LOCATION_ARCHIVE,
                    'deleted_at' => null,
                    'filesize' => filesize($localPath) ?: (int) ($row->filesize ?? 0),
                    'z_updated' => $now,
                    'z_updater' => 'recordings-reconcile-drift',
                ]);

                return 'repaired';
            }
        }

        // Stale local_path claimed but file gone.
        if ($localPath !== '' && ! $hasLocal) {
            if ($hasS3Key) {
                DB::table('recordings')->where('id', $row->id)->update([
                    'local_path' => null,
                    'location' => RecordingPathHelper::LOCATION_S3_ONLY,
                    'deleted_at' => null,
                    'z_updated' => $now,
                    'z_updater' => 'recordings-reconcile-drift',
                ]);

                return 'repaired';
            }

            DB::table('recordings')->where('id', $row->id)->update([
                'deleted_at' => $now,
                'local_path' => null,
                'z_updated' => $now,
                'z_updater' => 'recordings-reconcile-drift',
            ]);

            return 'repaired';
        }

        // s3_only but local file is back — restore archive pointer.
        if ($location === RecordingPathHelper::LOCATION_S3_ONLY && $hasLocal) {
            DB::table('recordings')->where('id', $row->id)->update([
                'location' => RecordingPathHelper::LOCATION_ARCHIVE,
                'deleted_at' => null,
                'z_updated' => $now,
                'z_updater' => 'recordings-reconcile-drift',
            ]);

            return 'repaired';
        }

        if (! $checkS3 || ! $hasS3Key) {
            return 'ok';
        }

        $existsOnS3 = $this->gatekeeper->objectExists($s3Key);
        if ($existsOnS3) {
            return 'ok';
        }

        // S3 object gone (lifecycle or manual delete).
        if ($hasLocal) {
            DB::table('recordings')->where('id', $row->id)->update([
                's3_key' => null,
                'location' => RecordingPathHelper::LOCATION_ARCHIVE,
                'deleted_at' => null,
                'z_updated' => $now,
                'z_updater' => 'recordings-reconcile-drift',
            ]);

            return 'repaired';
        }

        // No local, no S3 — drop the catalog row.
        DB::table('recordings')->where('id', $row->id)->delete();

        return 'removed';
    }

    private function findArchiveAbsolute(string $tenant, string $filename, int $epoch): ?string
    {
        $archive = Storage::disk(self::ARCHIVE_DISK);
        $expected = $this->paths->archiveRelativePath($tenant, $epoch, $filename);
        if ($archive->exists($expected)) {
            return $archive->path($expected);
        }

        // Slow fallback: scan tenant tree for basename (broken date path).
        $tenantRoot = $tenant;
        if (! $archive->exists($tenantRoot)) {
            return null;
        }

        foreach ($archive->allFiles($tenantRoot) as $rel) {
            if (basename($rel) === $filename && ! str_contains($rel, '/deletes/')) {
                return $archive->path($rel);
            }
        }

        return null;
    }
}
