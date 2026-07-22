<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Call recordings index — SQLite catalog (R1.5) with spool/archive filesystem fallback (Rule 1).
 */
class RecordingIndexService
{
    private const SPOOL_DISK = 'recordings';

    private const ARCHIVE_DISK = 'recordings_archive';

    public function __construct(
        private readonly RecordingSchemaService $schema,
        private readonly RecordingFilenameParser $parser,
        private readonly RecordingPathHelper $paths,
    ) {}

    /**
     * @param  array{tenant?:?string, tenants?:?list<string>, from?:?int, to?:?int, search?:?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $tenantNames = $this->tenantNameMap();
        $rows = [];

        if ($this->schema->tableExists()) {
            $rows = $this->listFromDatabase($filters, $tenantNames);
        }

        $indexed = [];
        foreach ($rows as $row) {
            $tenantKey = (string) ($row['tenant'] ?? $row['cluster'] ?? '');
            $indexed[$tenantKey.'|'.$row['filename']] = true;
        }

        foreach ($this->scanSpool($filters, $tenantNames, $indexed) as $row) {
            $rows[] = $row;
        }

        if ($rows === []) {
            $rows = $this->scanSpool($filters, $tenantNames, []);
        }

        usort($rows, static fn (array $a, array $b): int => ($b['epoch'] <=> $a['epoch']));

        return $rows;
    }

    /**
     * Resolve a recording id to an absolute filesystem path for streaming.
     */
    public function absolutePathFromId(string $id): ?string
    {
        if ($this->paths->isKsuidId($id) && $this->schema->tableExists()) {
            $row = DB::table('recordings')->where('id', $id)->whereNull('deleted_at')->first();
            if ($row !== null) {
                $path = (string) ($row->local_path ?? '');
                if ($path !== '' && is_file($path)) {
                    return $path;
                }
            }
        }

        $rel = $this->relativePathFromLegacyId($id);
        if ($rel === null) {
            return null;
        }

        $spool = Storage::disk(self::SPOOL_DISK);
        if ($spool->exists($rel)) {
            return $spool->path($rel);
        }

        [$tenant, $filename] = explode('/', $rel, 2);
        $archiveRel = $this->findInArchive($tenant, $filename);
        if ($archiveRel !== null) {
            return Storage::disk(self::ARCHIVE_DISK)->path($archiveRel);
        }

        return null;
    }

    /**
     * S3 object key for a catalog row (S7), or null if none.
     */
    public function s3KeyFromId(string $id): ?string
    {
        if (! $this->paths->isKsuidId($id) || ! $this->schema->tableExists()) {
            return null;
        }

        $row = DB::table('recordings')->where('id', $id)->whereNull('deleted_at')->first();
        if ($row === null) {
            return null;
        }

        $key = (string) ($row->s3_key ?? '');

        return $key !== '' ? $key : null;
    }

    /** Filename for Content-Disposition when streaming from S3. */
    public function filenameFromId(string $id): ?string
    {
        if (! $this->paths->isKsuidId($id) || ! $this->schema->tableExists()) {
            return null;
        }

        $row = DB::table('recordings')->where('id', $id)->whereNull('deleted_at')->first();

        return $row !== null ? (string) $row->filename : null;
    }

    /**
     * Cluster/tenant shortuid (or spool dir name) for a recording id, for scope checks.
     */
    public function clusterFromId(string $id): ?string
    {
        if ($this->paths->isKsuidId($id) && $this->schema->tableExists()) {
            $row = DB::table('recordings')->where('id', $id)->whereNull('deleted_at')->first();
            if ($row !== null && isset($row->cluster) && (string) $row->cluster !== '') {
                return (string) $row->cluster;
            }
        }

        $rel = $this->relativePathFromLegacyId($id);
        if ($rel === null) {
            return null;
        }

        $parts = explode('/', $rel, 2);

        return $parts[0] !== '' ? $parts[0] : null;
    }

    /** @deprecated Use absolutePathFromId */
    public function relativePathFromId(string $id): ?string
    {
        $abs = $this->absolutePathFromId($id);

        return $abs !== null ? $abs : null;
    }

    /** @deprecated Use absolutePathFromId */
    public function absolutePath(string $relativePath): string
    {
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }

        return Storage::disk(self::SPOOL_DISK)->path($relativePath);
    }

    /**
     * @param  array<string, string>  $tenantNames
     * @return array<int, array<string, mixed>>
     */
    private function listFromDatabase(array $filters, array $tenantNames): array
    {
        $query = DB::table('recordings')->whereNull('deleted_at');

        $tenants = $this->normalizeTenantFilters($filters);
        if ($tenants !== null) {
            if ($tenants === []) {
                return [];
            }
            $query->whereIn('cluster', $tenants);
        }

        $from = $filters['from'] ?? null;
        if ($from !== null) {
            $query->where('epoch', '>=', $from);
        }

        $to = $filters['to'] ?? null;
        if ($to !== null) {
            $query->where('epoch', '<=', $to);
        }

        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : null;

        $rows = [];
        foreach ($query->get() as $row) {
            $item = $this->rowFromDatabase($row, $tenantNames);
            if ($search !== null && $search !== '' && ! $this->matchesSearch($item, $search)) {
                continue;
            }
            if ($item['playable']) {
                $rows[] = $item;
            } elseif ($row->location === RecordingPathHelper::LOCATION_S3_ONLY) {
                $rows[] = $item;
            } elseif (! empty($row->s3_key)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $tenantNames
     * @param  array<string, true>  $skipKeys
     * @return array<int, array<string, mixed>>
     */
    private function scanSpool(array $filters, array $tenantNames, array $skipKeys): array
    {
        $disk = Storage::disk(self::SPOOL_DISK);
        $rows = [];

        $tenants = $this->normalizeTenantFilters($filters);
        $tenantAllow = $tenants !== null ? array_fill_keys($tenants, true) : null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : null;

        if ($tenantAllow !== null && $tenantAllow === []) {
            return [];
        }

        foreach ($disk->directories() as $dir) {
            $tenant = basename($dir);
            if ($tenantAllow !== null && ! isset($tenantAllow[$tenant])) {
                continue;
            }

            foreach ($disk->files($dir) as $rel) {
                if (! str_ends_with(strtolower($rel), '.wav')) {
                    continue;
                }

                $filename = basename($rel);
                $key = $tenant.'|'.$filename;
                if (isset($skipKeys[$key])) {
                    continue;
                }

                $row = $this->rowFromSpool($tenant, $filename, $rel, $tenantNames);
                if ($from !== null && $row['epoch'] > 0 && $row['epoch'] < $from) {
                    continue;
                }
                if ($to !== null && $row['epoch'] > 0 && $row['epoch'] > $to) {
                    continue;
                }
                if ($search !== null && $search !== '' && ! $this->matchesSearch($row, $search)) {
                    continue;
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $tenantNames
     * @return array<string, mixed>
     */
    private function rowFromDatabase(object $row, array $tenantNames): array
    {
        $tenant = (string) $row->cluster;
        $filename = (string) $row->filename;
        $localPath = (string) ($row->local_path ?? '');
        $playable = $localPath !== '' && is_file($localPath);

        if (! $playable && $localPath !== '') {
            $archiveRel = $this->findInArchive($tenant, $filename);
            if ($archiveRel !== null) {
                $localPath = Storage::disk(self::ARCHIVE_DISK)->path($archiveRel);
                $playable = is_file($localPath);
            }
        }

        $filesize = (int) ($row->filesize ?? 0);
        if ($playable && $filesize === 0) {
            $filesize = filesize($localPath) ?: 0;
        }

        $s3Key = (string) ($row->s3_key ?? '');
        $location = (string) ($row->location ?? RecordingPathHelper::LOCATION_ARCHIVE);
        $onS3 = $s3Key !== '';
        if (! $playable && $onS3) {
            $playable = true;
        }

        return [
            'id' => (string) $row->id,
            'tenant' => $tenant,
            'tenant_name' => $tenantNames[$tenant] ?? $tenant,
            'filename' => $filename,
            'epoch' => (int) $row->epoch,
            'created_at' => (int) $row->epoch > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int) $row->epoch) : null,
            'dnid' => $row->dnid,
            'callerid' => $row->callerid,
            'queue' => $row->queue,
            'extension' => $row->extension,
            'is_queue' => $row->queue !== null,
            'location' => $location,
            'on_s3' => $onS3,
            'storage' => $this->storageCode($location, $onS3),
            'archived' => $location === RecordingPathHelper::LOCATION_S3_ONLY,
            'filesize' => $filesize,
            'playable' => $playable,
        ];
    }

    /**
     * Canonical storage code for SPA badges (S7).
     * spool | local | local_s3 | s3_only
     */
    private function storageCode(string $location, bool $onS3): string
    {
        if ($location === RecordingPathHelper::LOCATION_S3_ONLY) {
            return 's3_only';
        }
        if ($location === RecordingPathHelper::LOCATION_SPOOL) {
            return $onS3 ? 'spool_s3' : 'spool';
        }
        if ($onS3) {
            return 'local_s3';
        }

        return 'local';
    }

    /**
     * @param  array<string, string>  $tenantNames
     * @return array<string, mixed>
     */
    private function rowFromSpool(string $tenant, string $filename, string $rel, array $tenantNames): array
    {
        $parsed = $this->parser->parse($tenant, $filename);
        $filesize = 0;
        try {
            $filesize = (int) Storage::disk(self::SPOOL_DISK)->size($rel);
        } catch (\Throwable) {
            // non-fatal
        }

        return array_merge($parsed, [
            'id' => $this->paths->legacyIdFromSpoolPath($tenant, $filename),
            'tenant_name' => $tenantNames[$tenant] ?? $tenant,
            'location' => RecordingPathHelper::LOCATION_SPOOL,
            'on_s3' => false,
            'storage' => 'spool',
            'archived' => false,
            'filesize' => $filesize,
            'playable' => true,
        ]);
    }

    private function relativePathFromLegacyId(string $id): ?string
    {
        if ($this->paths->isKsuidId($id)) {
            return null;
        }

        return $this->paths->decodeLegacyId($id);
    }

    private function findInArchive(string $tenant, string $filename): ?string
    {
        $archive = Storage::disk(self::ARCHIVE_DISK);

        // Deterministic layout: {tenant}/{yyyy}/{mm}/{dd}/{filename} keyed off the epoch in the name.
        $parsed = $this->parser->parse($tenant, $filename);
        $expected = $this->paths->archiveRelativePath($tenant, (int) $parsed['epoch'], $filename);
        if ($archive->exists($expected)) {
            return $expected;
        }

        $deletePath = "deletes/{$tenant}/{$filename}";
        if ($archive->exists($deletePath)) {
            return $deletePath;
        }

        // Fallback scan (e.g. clock skew in name). Rule 1: never let a permission
        // error on one tenant dir break the whole listing.
        if ($archive->exists($tenant)) {
            try {
                foreach ($archive->allFiles($tenant) as $rel) {
                    if (strcasecmp(basename($rel), $filename) === 0) {
                        return $rel;
                    }
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function tenantNameMap(): array
    {
        $map = [];
        try {
            foreach (DB::table('cluster')->get(['shortuid', 'pkey']) as $row) {
                if (! empty($row->shortuid) && ! empty($row->pkey)) {
                    $map[(string) $row->shortuid] = (string) $row->pkey;
                }
            }
        } catch (\Throwable) {
            // fall back to shortuid
        }

        return $map;
    }

    /**
     * Normalize tenant / tenants filters into an IN list.
     *
     * @param  array{tenant?:?string, tenants?:?list<string>}  $filters
     * @return list<string>|null  null = no filter; [] = match nothing; else IN list
     */
    private function normalizeTenantFilters(array $filters): ?array
    {
        $set = [];
        $hasTenantsKey = array_key_exists('tenants', $filters);

        if ($hasTenantsKey && is_array($filters['tenants'])) {
            foreach ($filters['tenants'] as $t) {
                $t = trim((string) $t);
                if ($t !== '') {
                    $set[$t] = true;
                }
            }
        }

        $single = isset($filters['tenant']) ? trim((string) $filters['tenant']) : '';
        if ($single !== '') {
            $set[$single] = true;
        }

        if (! $hasTenantsKey && $single === '') {
            return null;
        }

        return array_keys($set);
    }

    private function matchesSearch(array $row, string $needle): bool
    {
        foreach (['filename', 'dnid', 'callerid', 'queue', 'extension', 'tenant', 'tenant_name'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && str_contains(strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }
}
