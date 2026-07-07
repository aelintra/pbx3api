<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill recordings catalog rows from the local archive tree.
 */
class RecordingReconcileService
{
    private const ARCHIVE_DISK = 'recordings_archive';

    public function __construct(
        private readonly RecordingSchemaService $schema,
        private readonly RecordingFilenameParser $parser,
    ) {}

    /**
     * @return array{inserted: int, skipped: int}
     */
    public function run(?string $tenantFilter = null): array
    {
        if (! $this->schema->ensureTable()) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $archive = Storage::disk(self::ARCHIVE_DISK);
        $stats = ['inserted' => 0, 'skipped' => 0];

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
}
