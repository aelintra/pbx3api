<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Move stable spool recordings to the local archive and index rows (R1.5).
 */
class RecordingOffloadService
{
    private const SPOOL_DISK = 'recordings';

    private const ARCHIVE_DISK = 'recordings_archive';

    public function __construct(
        private readonly RecordingSchemaService $schema,
        private readonly RecordingFilenameParser $parser,
        private readonly RecordingPathHelper $paths,
    ) {}

    /**
     * @return array{offloaded: int, skipped: int, errors: int}
     */
    public function run(): array
    {
        if (! config('pbx3_recordings.offload_enabled')) {
            return ['offloaded' => 0, 'skipped' => 0, 'errors' => 0];
        }

        if (! $this->schema->ensureTable()) {
            return ['offloaded' => 0, 'skipped' => 0, 'errors' => 1];
        }

        $stableSeconds = max(60, (int) config('pbx3_recordings.stable_minutes') * 60);
        $cutoff = time() - $stableSeconds;

        $spool = Storage::disk(self::SPOOL_DISK);
        $archive = Storage::disk(self::ARCHIVE_DISK);

        $stats = ['offloaded' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($spool->directories() as $dir) {
            $tenant = basename($dir);
            foreach ($spool->files($dir) as $rel) {
                if (! str_ends_with(strtolower($rel), '.wav')) {
                    continue;
                }

                $abs = $spool->path($rel);
                $mtime = @filemtime($abs);
                if ($mtime === false || $mtime > $cutoff) {
                    $stats['skipped']++;

                    continue;
                }

                $filename = basename($rel);
                if ($this->offloadFile($tenant, $filename, $rel, $spool, $archive)) {
                    $stats['offloaded']++;
                } else {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    private function offloadFile(
        string $tenant,
        string $filename,
        string $spoolRel,
        \Illuminate\Contracts\Filesystem\Filesystem $spool,
        \Illuminate\Contracts\Filesystem\Filesystem $archive,
    ): bool {
        $parsed = $this->parser->parse($tenant, $filename);
        $archiveRel = $this->paths->archiveRelativePath($tenant, (int) $parsed['epoch'], $filename);

        $archiveDir = dirname($archiveRel);
        if ($archiveDir !== '.' && ! $archive->exists($archiveDir)) {
            $archive->makeDirectory($archiveDir);
        }

        try {
            if (! $this->moveFile($spool, $spoolRel, $archive, $archiveRel)) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('recording offload move failed', [
                'from' => $spoolRel,
                'to' => $archiveRel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $filesize = 0;
        try {
            $filesize = (int) $archive->size($archiveRel);
        } catch (\Throwable) {
            // non-fatal
        }

        $now = gmdate('Y-m-d H:i:s');
        $localPath = $archive->path($archiveRel);

        $existing = DB::table('recordings')
            ->where('cluster', $tenant)
            ->where('filename', $filename)
            ->first();

        if ($existing !== null) {
            DB::table('recordings')->where('id', $existing->id)->update([
                'epoch' => (int) $parsed['epoch'],
                'callerid' => $parsed['callerid'],
                'dnid' => $parsed['dnid'],
                'queue' => $parsed['queue'],
                'extension' => $parsed['extension'],
                'local_path' => $localPath,
                'location' => RecordingPathHelper::LOCATION_ARCHIVE,
                'filesize' => $filesize,
                'deleted_at' => null,
                'z_updated' => $now,
                'z_updater' => 'recordings-offload',
            ]);

            return true;
        }

        DB::table('recordings')->insert([
            'id' => generate_ksuid(),
            'cluster' => $tenant,
            'epoch' => (int) $parsed['epoch'],
            'callerid' => $parsed['callerid'],
            'dnid' => $parsed['dnid'],
            'queue' => $parsed['queue'],
            'extension' => $parsed['extension'],
            'filename' => $filename,
            'local_path' => $localPath,
            's3_key' => null,
            'location' => RecordingPathHelper::LOCATION_ARCHIVE,
            'filesize' => $filesize,
            'deleted_at' => null,
            'z_created' => $now,
            'z_updated' => $now,
            'z_updater' => 'recordings-offload',
        ]);

        return true;
    }

    private function moveFile(
        \Illuminate\Contracts\Filesystem\Filesystem $fromDisk,
        string $fromRel,
        \Illuminate\Contracts\Filesystem\Filesystem $toDisk,
        string $toRel,
    ): bool {
        $fromAbs = $fromDisk->path($fromRel);
        $toAbs = $toDisk->path($toRel);

        if (! @rename($fromAbs, $toAbs)) {
            if (! @copy($fromAbs, $toAbs)) {
                return false;
            }

            return $fromDisk->delete($fromRel);
        }

        return true;
    }
}
