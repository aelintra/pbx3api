<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\Storage;

/**
 * Filesystem index for call recordings (Phase R1 — local-first, no DB, no S3).
 *
 * Layout: {recordings disk root}/{tenant}/{filename}.wav
 * Capture (pbx3cagi MixMonitor) names files:
 *   regular : {epoch}-{tenant}-{calledid}-{clid}.wav  (API: dnid, callerid)
 *   queue   : {epoch}-{tenant}-{queue}-{extension}-{clid}.wav  (swept)
 *             Qexec{epoch}-...                                       (unswept)
 *
 * Parsing is best-effort; the raw filename is always returned so an operator
 * can still identify a recording the parser could not fully decompose.
 */
class RecordingIndexService
{
    private const DISK = 'recordings';

    /**
     * List recordings, newest first, with optional filters.
     *
     * @param  array{tenant?:?string, from?:?int, to?:?int, search?:?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $disk = Storage::disk(self::DISK);
        $rows = [];

        $tenantFilter = $filters['tenant'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : null;

        foreach ($disk->directories() as $dir) {
            $tenant = basename($dir);
            if ($tenantFilter !== null && $tenant !== $tenantFilter) {
                continue;
            }

            foreach ($disk->files($dir) as $rel) {
                if (! str_ends_with(strtolower($rel), '.wav')) {
                    continue;
                }

                $row = $this->parse($tenant, $rel);

                if ($from !== null && $row['epoch'] > 0 && $row['epoch'] < $from) {
                    continue;
                }
                if ($to !== null && $row['epoch'] > 0 && $row['epoch'] > $to) {
                    continue;
                }
                if ($search !== null && $search !== '' && ! $this->matchesSearch($row, $search)) {
                    continue;
                }

                try {
                    $row['filesize'] = (int) $disk->size($rel);
                } catch (\Throwable) {
                    $row['filesize'] = 0;
                }

                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $a, array $b): int => ($b['epoch'] <=> $a['epoch']));

        return $rows;
    }

    /**
     * Resolve an opaque recording id back to a validated relative path
     * ({tenant}/{filename}.wav) that lives on the recordings disk.
     */
    public function relativePathFromId(string $id): ?string
    {
        $decoded = base64_decode(strtr($id, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        // Reject traversal / absolute paths; require {tenant}/{file}.wav shape.
        if (str_contains($decoded, "\0") || str_contains($decoded, '..') || str_starts_with($decoded, '/')) {
            return null;
        }
        if (preg_match('#^[^/]+/[^/]+\.wav$#i', $decoded) !== 1) {
            return null;
        }

        if (! Storage::disk(self::DISK)->exists($decoded)) {
            return null;
        }

        return $decoded;
    }

    /** Absolute filesystem path for a validated relative path. */
    public function absolutePath(string $relativePath): string
    {
        return Storage::disk(self::DISK)->path($relativePath);
    }

    private function idFromRelativePath(string $rel): string
    {
        return rtrim(strtr(base64_encode($rel), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $tenant, string $rel): array
    {
        $filename = basename($rel);
        $base = preg_replace('/\.wav$/i', '', $filename);

        $isQueueUnmatched = false;
        if (stripos($base, 'Qexec') === 0) {
            $isQueueUnmatched = true;
            $base = substr($base, strlen('Qexec'));
        }

        $tokens = explode('-', $base);

        $epoch = 0;
        if (isset($tokens[0]) && preg_match('/^(\d+)/', $tokens[0], $m) === 1) {
            $epoch = (int) $m[1];
        }

        $dnid = null;
        $callerid = null;
        $queue = null;
        $extension = null;

        $count = count($tokens);
        if ($count >= 5) {
            // {epoch}-{tenant}-{queue}-{extension}-{callerid}
            $queue = $tokens[2];
            $extension = $tokens[3];
            $callerid = $tokens[4];
        } elseif ($count === 4) {
            // {epoch}-{tenant}-{calledid}-{clid}
            $dnid = $tokens[2];
            $callerid = $tokens[3];
        } elseif ($count === 3) {
            $dnid = $tokens[2];
        }

        return [
            'id' => $this->idFromRelativePath($rel),
            'tenant' => $tenant,
            'filename' => $filename,
            'epoch' => $epoch,
            'created_at' => $epoch > 0 ? gmdate('Y-m-d\TH:i:s\Z', $epoch) : null,
            'dnid' => $dnid,
            'callerid' => $callerid,
            'queue' => $queue,
            'extension' => $extension,
            'is_queue' => $queue !== null || $isQueueUnmatched,
            'filesize' => 0,
        ];
    }

    private function matchesSearch(array $row, string $needle): bool
    {
        foreach (['filename', 'dnid', 'callerid', 'queue', 'extension'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && str_contains(strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }
}
