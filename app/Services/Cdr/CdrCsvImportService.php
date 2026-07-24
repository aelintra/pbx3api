<?php

namespace App\Services\Cdr;

use PDO;
use RuntimeException;

/**
 * Import Asterisk cdr-csv Master.csv (classic column order) into a lab master.db.
 *
 * Golden dialect (cdr.conf [csv] loguniqueid=yes loguserfield=yes newcdrcolumns=no):
 * accountcode, src, dst, dcontext, clid, channel, dstchannel, lastapp, lastdata,
 * start, answer, end, duration, billsec, disposition, amaflags, uniqueid, userfield
 *
 * No header row. .gz supported. Path-safe like CdrFixtureService.
 */
final class CdrCsvImportService
{
    /** Classic Master.csv field count with uniqueid + userfield. */
    public const EXPECTED_FIELDS = 18;

    public function __construct(
        private readonly CdrIndexService $index,
        private readonly CdrFixtureService $fixture,
    ) {
    }

    /**
     * @param  array{
     *   file: string,
     *   path?: string|null,
     *   truncate?: bool,
     *   limit?: int|null,
     *   allow_live?: bool,
     *   force?: bool
     * }  $options
     * @return array{
     *   csv: string,
     *   path: string,
     *   created: bool,
     *   truncated: bool,
     *   read: int,
     *   inserted: int,
     *   skipped: int
     * }
     */
    public function import(array $options): array
    {
        $csvPath = trim((string) ($options['file'] ?? ''));
        if ($csvPath === '' || ! is_readable($csvPath)) {
            throw new RuntimeException('CDR CSV not readable: '.($csvPath !== '' ? $csvPath : '(empty)'));
        }

        $sqlitePath = $this->fixture->resolvePath(isset($options['path']) ? (string) $options['path'] : null);
        $allowLive = (bool) ($options['allow_live'] ?? false);
        $force = (bool) ($options['force'] ?? false)
            || filter_var(env('PBX3_CDR_FIXTURE', false), FILTER_VALIDATE_BOOL);
        $this->fixture->assertWritable($sqlitePath, $allowLive, $force);

        $truncate = (bool) ($options['truncate'] ?? false);
        $limit = isset($options['limit']) && is_numeric($options['limit'])
            ? max(0, (int) $options['limit'])
            : null;

        $created = false;
        if (! is_file($sqlitePath)) {
            $dir = dirname($sqlitePath);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Cannot create directory for CDR SQLite: {$dir}");
            }
            touch($sqlitePath);
            $created = true;
        }

        $pdo = new PDO('sqlite:'.$sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        CdrSchema::ensureTable($pdo);

        if ($truncate) {
            $pdo->exec('DELETE FROM cdr');
        }

        $fh = $this->openCsv($csvPath);
        $ins = $pdo->prepare(
            'INSERT INTO cdr (calldate, clid, src, dst, dcontext, channel, dstchannel,'
            .' lastapp, lastdata, duration, billsec, disposition, amaflags,'
            .' accountcode, uniqueid, userfield, linkedid)'
            .' VALUES (:calldate, :clid, :src, :dst, :dcontext, :channel, :dstchannel,'
            .' :lastapp, :lastdata, :duration, :billsec, :disposition, :amaflags,'
            .' :accountcode, :uniqueid, :userfield, :linkedid)'
        );

        $read = 0;
        $inserted = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            while (($row = fgetcsv($fh)) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                // Skip accidental header if someone added one.
                if ($read === 0 && isset($row[0]) && strcasecmp(trim((string) $row[0]), 'accountcode') === 0) {
                    continue;
                }
                $read++;
                if ($limit !== null && $inserted >= $limit) {
                    break;
                }

                $mapped = $this->mapRow($row);
                if ($mapped === null) {
                    $skipped++;

                    continue;
                }
                $ins->execute($mapped);
                $inserted++;

                if ($inserted % 500 === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fclose($fh);

            throw $e;
        }
        fclose($fh);

        return [
            'csv' => $csvPath,
            'path' => $sqlitePath,
            'created' => $created,
            'truncated' => $truncate,
            'read' => $read,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<string|null>  $row
     * @return array<string, mixed>|null
     */
    public function mapRow(array $row): ?array
    {
        if (count($row) < self::EXPECTED_FIELDS) {
            // Tolerate trailing empties stripped by some exporters.
            if (count($row) < 16) {
                return null;
            }
            $row = array_pad($row, self::EXPECTED_FIELDS, '');
        }

        $accountcode = trim((string) ($row[0] ?? ''));
        $src = trim((string) ($row[1] ?? ''));
        $dst = trim((string) ($row[2] ?? ''));
        $dcontext = trim((string) ($row[3] ?? ''));
        $clid = (string) ($row[4] ?? '');
        $channel = (string) ($row[5] ?? '');
        $dstchannel = (string) ($row[6] ?? '');
        $lastapp = (string) ($row[7] ?? '');
        $lastdata = (string) ($row[8] ?? '');
        $start = trim((string) ($row[9] ?? ''));
        // $answer = $row[10]; $end = $row[11]; — not stored in Phase 6 schema
        $duration = (int) ($row[12] ?? 0);
        $billsec = (int) ($row[13] ?? 0);
        $disposition = strtoupper(trim((string) ($row[14] ?? '')));
        $amaflags = $this->normalizeAmaflags($row[15] ?? '');
        $uniqueid = trim((string) ($row[16] ?? ''));
        $userfield = (string) ($row[17] ?? '');

        if ($start === '') {
            return null;
        }

        return [
            'calldate' => $start,
            'clid' => $clid,
            'src' => $src,
            'dst' => $dst,
            'dcontext' => $dcontext,
            'channel' => $channel,
            'dstchannel' => $dstchannel,
            'lastapp' => $lastapp,
            'lastdata' => $lastdata,
            'duration' => $duration,
            'billsec' => $billsec,
            'disposition' => $disposition !== '' ? $disposition : 'UNKNOWN',
            'amaflags' => $amaflags,
            'accountcode' => $accountcode,
            'uniqueid' => $uniqueid,
            'userfield' => $userfield,
            'linkedid' => '',
        ];
    }

    private function normalizeAmaflags(mixed $raw): int
    {
        if (is_numeric($raw)) {
            return (int) $raw;
        }
        $s = strtoupper(trim((string) $raw));

        return match ($s) {
            'DEFAULT' => 0,
            'OMIT' => 1,
            'BILLING' => 2,
            'DOCUMENTATION' => 3,
            default => 0,
        };
    }

    /** @return resource */
    private function openCsv(string $path)
    {
        $uri = str_ends_with(strtolower($path), '.gz')
            ? 'compress.zlib://'.$path
            : $path;
        $fh = fopen($uri, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open CDR CSV: {$path}");
        }

        return $fh;
    }
}
