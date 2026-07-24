<?php

namespace App\Services\Cdr;

use PDO;

/**
 * Velocity V1 — scanner input over master.db CDR.
 *
 * Window T + high-cost dst prefixes; excludes empty dst and obvious internal shapes.
 * Spec: FLEET_TOLL_FRAUD_VELOCITY_REQUIREMENTS.md § V1 query contract.
 */
final class VelocityCdrQuery
{
    public function __construct(
        private readonly CdrIndexService $index,
    ) {
    }

    /**
     * @param  list<string>|null  $prefixes  null = config PBX3_OPS_VELOCITY_PREFIXES
     * @return array{
     *   available: bool,
     *   path: string,
     *   window_minutes: int,
     *   prefixes: list<string>,
     *   from: string,
     *   total: int,
     *   rows: list<array<string, mixed>>,
     *   by_src: array<string, int>
     * }
     */
    public function candidates(?int $windowMinutes = null, ?array $prefixes = null): array
    {
        $windowMinutes = max(1, $windowMinutes ?? (int) config('pbx3_ops.velocity_window_minutes', 5));
        $prefixes = $this->normalizePrefixes($prefixes ?? $this->configPrefixes());
        $path = $this->index->path();
        $from = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

        $empty = [
            'available' => false,
            'path' => $path,
            'window_minutes' => $windowMinutes,
            'prefixes' => $prefixes,
            'from' => $from,
            'total' => 0,
            'rows' => [],
            'by_src' => [],
        ];

        if (! $this->index->isAvailable() || $prefixes === []) {
            return $empty;
        }

        $pdo = $this->openReadOnly($path);
        [$whereSql, $params] = $this->buildWhere($from, $prefixes);

        $sql = 'SELECT calldate, clid, src, dst, dcontext, channel, dstchannel,'
            .' lastapp, duration, billsec, disposition, accountcode, uniqueid, linkedid'
            .' FROM cdr'.$whereSql
            .' ORDER BY calldate ASC, rowid ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rows = [];
        $bySrc = [];
        foreach ($raw as $row) {
            if ($this->isInternalDst((string) ($row['dst'] ?? ''))) {
                continue;
            }
            $rows[] = $row;
            $src = trim((string) ($row['src'] ?? ''));
            if ($src === '') {
                $src = '(unknown)';
            }
            $bySrc[$src] = ($bySrc[$src] ?? 0) + 1;
        }

        return [
            'available' => true,
            'path' => $path,
            'window_minutes' => $windowMinutes,
            'prefixes' => $prefixes,
            'from' => $from,
            'total' => count($rows),
            'rows' => $rows,
            'by_src' => $bySrc,
        ];
    }

    /**
     * @return list<string>
     */
    private function configPrefixes(): array
    {
        $raw = (string) config('pbx3_ops.velocity_prefixes', '');

        return $this->normalizePrefixes(preg_split('/\s*,\s*/', $raw) ?: []);
    }

    /**
     * @param  list<string>|array<int, mixed>  $prefixes
     * @return list<string>
     */
    private function normalizePrefixes(array $prefixes): array
    {
        $out = [];
        foreach ($prefixes as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  list<string>  $prefixes
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(string $from, array $prefixes): array
    {
        $params = ['from' => $from];
        $prefixClauses = [];
        foreach ($prefixes as $i => $prefix) {
            $key = 'p'.$i;
            // Escape LIKE metacharacters in the prefix itself; append % for prefix match.
            $prefixClauses[] = 'dst LIKE :'.$key.' ESCAPE \'\\\'';
            $params[$key] = $this->escapeLike($prefix).'%';
        }

        $where = ' WHERE calldate >= :from'
            .' AND dst IS NOT NULL AND TRIM(dst) != \'\''
            .' AND ('.implode(' OR ', $prefixClauses).')';

        return [$where, $params];
    }

    /**
     * Heuristic: shortuid-only / local extension shapes — not premium PSTN.
     * Documented for V2; keep conservative (false negatives OK for V1).
     */
    public function isInternalDst(string $dst): bool
    {
        $dst = trim($dst);
        if ($dst === '') {
            return true;
        }
        // 3–6 digit local / shortuid-style (no + or trunk digits)
        if (preg_match('/^\d{3,6}$/', $dst) === 1) {
            return true;
        }
        // Pure alphabetic / shortuid tokens
        if (preg_match('/^[a-z][a-z0-9]{4,7}$/i', $dst) === 1) {
            return true;
        }

        return false;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function openReadOnly(string $path): PDO
    {
        $uri = 'file:'.$path.'?mode=ro';

        try {
            $pdo = new PDO('sqlite:'.$uri, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException) {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
        }
        $pdo->exec('PRAGMA busy_timeout = 3000');

        return $pdo;
    }
}
