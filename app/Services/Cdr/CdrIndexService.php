<?php

namespace App\Services\Cdr;

use PDO;
use PDOException;

/**
 * Phase 6: read Asterisk cdr_sqlite3_custom master.db (search HoR).
 */
class CdrIndexService
{
    public function path(): string
    {
        return (string) config('pbx3_cdr.sqlite_path', '/var/log/asterisk/master.db');
    }

    public function isAvailable(): bool
    {
        $path = $this->path();

        return is_file($path) && is_readable($path);
    }

    /**
     * @param  array{
     *   from?: string|null,
     *   to?: string|null,
     *   search?: string|null,
     *   accountcode?: string|null,
     *   disposition?: string|null,
     *   limit?: int|null,
     *   offset?: int|null
     * }  $filters
     * @return array{available: bool, path: string, total: int, limit: int, offset: int, rows: list<array<string, mixed>>}
     */
    public function list(array $filters = []): array
    {
        $path = $this->path();
        $limit = max(1, min(
            (int) ($filters['limit'] ?? config('pbx3_cdr.default_limit', 100)),
            (int) config('pbx3_cdr.max_limit', 500)
        ));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        if (! $this->isAvailable()) {
            return [
                'available' => false,
                'path' => $path,
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'rows' => [],
            ];
        }

        $pdo = $this->openReadOnly();

        [$whereSql, $params] = $this->buildWhere($filters);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM cdr'.$whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT * FROM cdr'.$whereSql
            .' ORDER BY calldate DESC, rowid DESC'
            .' LIMIT '.$limit.' OFFSET '.$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'available' => true,
            'path' => $path,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'rows' => array_map([$this, 'normalizeRow'], $rows),
        ];
    }

    /**
     * Create helpful indexes (safe while Asterisk holds the writer; run from prune).
     */
    public function ensureIndexes(): void
    {
        $pdo = $this->openReadWrite();
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdr_calldate ON cdr(calldate)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdr_src ON cdr(src)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdr_dst ON cdr(dst)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdr_uniqueid ON cdr(uniqueid)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdr_accountcode ON cdr(accountcode)');
    }

    /**
     * Open read-write briefly for index/prune only.
     */
    public function openReadWrite(): PDO
    {
        $path = $this->path();
        if (! is_file($path)) {
            throw new \RuntimeException("CDR SQLite not found: {$path}");
        }

        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    private function openReadOnly(): PDO
    {
        $path = $this->path();
        $uri = 'file:'.$path.'?mode=ro';

        try {
            $pdo = new PDO('sqlite:'.$uri, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            // Fallback without URI mode (older SQLite builds)
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
        }
        $pdo->exec('PRAGMA busy_timeout = 3000');

        return $pdo;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        $from = isset($filters['from']) ? trim((string) $filters['from']) : '';
        $to = isset($filters['to']) ? trim((string) $filters['to']) : '';
        if ($from !== '') {
            $clauses[] = 'calldate >= :from';
            $params['from'] = $this->normalizeBound($from, false);
        }
        if ($to !== '') {
            $clauses[] = 'calldate <= :to';
            $params['to'] = $this->normalizeBound($to, true);
        }

        $account = isset($filters['accountcode']) ? trim((string) $filters['accountcode']) : '';
        if ($account !== '') {
            $clauses[] = 'accountcode = :accountcode';
            $params['accountcode'] = $account;
        }

        $disp = isset($filters['disposition']) ? trim((string) $filters['disposition']) : '';
        if ($disp !== '') {
            $clauses[] = 'disposition = :disposition';
            $params['disposition'] = strtoupper($disp);
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $clauses[] = '(clid LIKE :q ESCAPE \'\\\' OR src LIKE :q ESCAPE \'\\\''
                .' OR dst LIKE :q ESCAPE \'\\\' OR uniqueid LIKE :q ESCAPE \'\\\''
                .' OR accountcode LIKE :q ESCAPE \'\\\')';
            $params['q'] = $like;
        }

        $whereSql = $clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses);

        return [$whereSql, $params];
    }

    private function normalizeBound(string $raw, bool $endOfDay): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $endOfDay ? $raw.' 23:59:59' : $raw.' 00:00:00';
        }

        return $raw;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        foreach (['duration', 'billsec', 'amaflags', 'sequence'] as $intKey) {
            if (array_key_exists($intKey, $row) && $row[$intKey] !== null && $row[$intKey] !== '') {
                $row[$intKey] = (int) $row[$intKey];
            }
        }

        return $row;
    }
}
