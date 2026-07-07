<?php

namespace App\Services\Tenant;

use App\Models\Sysglobal;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Export/import a single tenant mini-DB for fleet moves (S8.6).
 * Preserves cluster.id (KSUID) and object KSUIDs. Trunks are instance-owned — not exported.
 */
class TenantMobilityService
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> Tenant-scoped tables (no trunks — instance-owned). */
    public const TENANT_DATA_TABLES = [
        'agent',
        'appl',
        'cos',
        'dateseg',
        'greeting',
        'holiday',
        'inroutes',
        'ipphone',
        'ipphonecosopen',
        'ipphonecosclosed',
        'ivrmenu',
        'page',
        'meetme',
        'queue',
        'recordings',
        'route',
        // users (Laravel auth) lives in instance SQL, not sqlite_create_tenant.sql — not exported
    ];

    /**
     * @param  array{include_recordings?: bool, output_path?: string|null}  $options
     * @return array{zip_path: string, manifest: array<string, mixed>}
     */
    public function export(string $identifier, array $options = []): array
    {
        $cluster = $this->resolveClusterRow($identifier);
        $aliases = cluster_identifier_aliases($cluster->shortuid ?? $cluster->pkey ?? $cluster->id);
        if ($aliases === []) {
            throw new \RuntimeException("Tenant not found: {$identifier}");
        }

        $shortuid = (string) $cluster->shortuid;
        $epoch = time();
        $exportDir = rtrim((string) config('pbx3_directory.tenant_export_dir'), '/');
        if (! is_dir($exportDir) && ! @mkdir($exportDir, 0755, true) && ! is_dir($exportDir)) {
            throw new \RuntimeException("Cannot create export directory: {$exportDir}");
        }

        $workDir = sys_get_temp_dir().'/pbx3tenant-export-'.bin2hex(random_bytes(4));
        if (! mkdir($workDir, 0700, true)) {
            throw new \RuntimeException('Cannot create temp export directory');
        }

        try {
            $miniDb = $workDir.'/tenant.sqlite.db';
            $rowCounts = $this->buildMiniDatabase($this->openPdo(), $cluster, $aliases, $miniDb);

            $mediaRoot = $workDir.'/media';
            $greetingBytes = $this->exportGreetingMedia($shortuid, $mediaRoot.'/greetings/'.$shortuid);
            $recordingBytes = 0;
            if (! empty($options['include_recordings'])) {
                $recordingBytes = $this->exportRecordingMedia($shortuid, $mediaRoot.'/recordings');
            }

            $globals = Sysglobal::query()->first();
            $manifest = [
                'schema_version' => self::SCHEMA_VERSION,
                'created_at' => gmdate('c', $epoch),
                'source_instance_id' => $globals->id ?? null,
                'source_fqdn' => $globals->fqdn ?? null,
                'tenant' => [
                    'id' => $cluster->id,
                    'shortuid' => $cluster->shortuid,
                    'pkey' => $cluster->pkey,
                    'fqdn' => $cluster->fqdn ?? null,
                ],
                'row_counts' => $rowCounts,
                'media' => [
                    'greetings_bytes' => $greetingBytes,
                    'recordings_bytes' => $recordingBytes,
                    'include_recordings' => ! empty($options['include_recordings']),
                ],
            ];
            file_put_contents($workDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $zipName = "pbx3tenant.{$shortuid}.{$epoch}.zip";
            $zipPath = $options['output_path'] ?? "{$exportDir}/{$zipName}";
            $this->createZip($workDir, $zipPath);

            return ['zip_path' => $zipPath, 'manifest' => $manifest];
        } finally {
            $this->removeTree($workDir);
        }
    }

    /**
     * @param  array{replace?: bool, skip_media?: bool}  $options
     * @return array<string, mixed>
     */
    public function import(string $zipPath, array $options = []): array
    {
        if (! is_file($zipPath)) {
            throw new \RuntimeException("Export zip not found: {$zipPath}");
        }

        $workDir = sys_get_temp_dir().'/pbx3tenant-import-'.bin2hex(random_bytes(4));
        if (! mkdir($workDir, 0700, true)) {
            throw new \RuntimeException('Cannot create temp import directory');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException("Cannot open zip: {$zipPath}");
            }
            $zip->extractTo($workDir);
            $zip->close();

            $manifestPath = $workDir.'/manifest.json';
            if (! is_file($manifestPath)) {
                throw new \RuntimeException('Export zip missing manifest.json');
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest) || ($manifest['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
                throw new \RuntimeException('Unsupported or missing manifest schema_version');
            }

            $tenant = $manifest['tenant'] ?? [];
            $clusterId = (string) ($tenant['id'] ?? '');
            $shortuid = (string) ($tenant['shortuid'] ?? '');
            if ($clusterId === '' || $shortuid === '') {
                throw new \RuntimeException('Manifest tenant.id and tenant.shortuid are required');
            }

            $miniDb = $workDir.'/tenant.sqlite.db';
            if (! is_file($miniDb)) {
                throw new \RuntimeException('Export zip missing tenant.sqlite.db');
            }

            $pdo = $this->openPdo();
            $conflict = $this->findImportConflict($clusterId, $shortuid, (string) ($tenant['pkey'] ?? ''));
            if ($conflict !== null && empty($options['replace'])) {
                throw new \RuntimeException("Tenant already exists ({$conflict}). Use --replace to overwrite.");
            }

            $pdo->beginTransaction();
            try {
                if ($conflict !== null && ! empty($options['replace'])) {
                    $aliases = cluster_identifier_aliases($shortuid);
                    $this->removeTenantRows($pdo, $aliases, $clusterId);
                }
                // mergeMiniDatabase opens a separate PDO to the mini-DB — SQLite forbids
                // ATTACH DATABASE while this connection has an open transaction.
                $imported = $this->mergeMiniDatabase($pdo, $miniDb);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $mediaResult = ['greetings' => false, 'recordings' => false];
            if (empty($options['skip_media'])) {
                $mediaResult = $this->installMedia($workDir, $shortuid, (string) ($tenant['pkey'] ?? ''));
            }

            // Regenerate Shorewall pbx3_inline_fqdn from cluster.fqdn (same as TenantController create/update/delete).
            pbx3_update_fqdn_inline_optional();

            return [
                'tenant' => $tenant,
                'imported_rows' => $imported,
                'media' => $mediaResult,
                'manifest' => $manifest,
            ];
        } finally {
            $this->removeTree($workDir);
        }
    }

    /**
     * @return object{id: string, shortuid: string, pkey: string, fqdn?: string|null}
     */
    public function resolveClusterRow(string $identifier): object
    {
        $row = DB::table('cluster')
            ->where('pkey', $identifier)
            ->orWhere('shortuid', $identifier)
            ->orWhere('id', $identifier)
            ->first(['id', 'shortuid', 'pkey', 'fqdn']);

        if ($row === null) {
            throw new \RuntimeException("Tenant not found: {$identifier}");
        }

        return $row;
    }

    private function openPdo(): \PDO
    {
        $path = config('database.connections.sqlite.database');
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 30000');

        return $pdo;
    }

    /**
     * @param  list<string>  $aliases
     * @return array<string, int>
     */
    private function buildMiniDatabase(\PDO $source, object $cluster, array $aliases, string $miniDbPath): array
    {
        if (file_exists($miniDbPath)) {
            unlink($miniDbPath);
        }
        touch($miniDbPath);

        $schemaPath = (string) config('pbx3_directory.tenant_schema_sql');
        if (! is_file($schemaPath)) {
            throw new \RuntimeException("Tenant schema SQL not found: {$schemaPath}");
        }
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new \RuntimeException("Cannot read tenant schema: {$schemaPath}");
        }

        $mini = new \PDO('sqlite:'.$miniDbPath);
        $mini->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $mini->exec($schema);

        $attachPath = str_replace("'", "''", $miniDbPath);
        $source->exec("ATTACH DATABASE '{$attachPath}' AS tenant_export");

        try {
            $rowCounts = [];
            $clusterId = (string) $cluster->id;
            $source->prepare('INSERT INTO tenant_export.cluster SELECT * FROM cluster WHERE id = ?')
                ->execute([$clusterId]);
            $rowCounts['cluster'] = 1;

            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
            foreach (self::TENANT_DATA_TABLES as $table) {
                if (! $this->tableExists($source, $table)) {
                    continue;
                }
                if (! $this->tableExistsOnConnection($source, 'tenant_export', $table)) {
                    continue;
                }
                $stmt = $source->prepare(
                    "INSERT INTO tenant_export.{$table} SELECT * FROM {$table} WHERE cluster IN ({$placeholders})"
                );
                $stmt->execute($aliases);
                $rowCounts[$table] = $stmt->rowCount();
            }

            return $rowCounts;
        } finally {
            $source->exec('DETACH DATABASE tenant_export');
        }
    }

    /**
     * Copy rows from mini-DB into the live DB. Uses a second PDO connection — not ATTACH —
     * because import() wraps this in BEGIN … COMMIT and SQLite rejects ATTACH in a transaction.
     *
     * @return array<string, int>
     */
    private function mergeMiniDatabase(\PDO $target, string $miniDbPath): array
    {
        $mini = new \PDO('sqlite:'.$miniDbPath);
        $mini->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $mini->exec('PRAGMA busy_timeout = 30000');

        $imported = [];
        if ($this->tableExists($mini, 'cluster')) {
            $imported['cluster'] = $this->copyTableRows($mini, $target, 'cluster');
        }

        foreach (self::TENANT_DATA_TABLES as $table) {
            if (! $this->tableExists($mini, $table) || ! $this->tableExists($target, $table)) {
                continue;
            }
            $imported[$table] = $this->copyTableRows($mini, $target, $table);
        }

        return $imported;
    }

    private function copyTableRows(\PDO $from, \PDO $to, string $table): int
    {
        $columns = $this->tableColumns($from, $table);
        if ($columns === []) {
            return 0;
        }
        $quoted = array_map(static fn (string $c) => '"'.$c.'"', $columns);
        $colList = implode(', ', $quoted);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $to->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})");

        $count = 0;
        foreach ($from->query("SELECT * FROM {$table}") as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $insert->execute($values);
            $count++;
        }

        return $count;
    }

    /** @return list<string> */
    private function tableColumns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_column($rows, 'name');
    }

    /**
     * @param  list<string>  $aliases
     */
    private function removeTenantRows(\PDO $pdo, array $aliases, string $clusterId): void
    {
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        foreach (self::TENANT_DATA_TABLES as $table) {
            if (! $this->tableExists($pdo, $table)) {
                continue;
            }
            $pdo->prepare("DELETE FROM {$table} WHERE cluster IN ({$placeholders})")->execute($aliases);
        }
        $pdo->prepare('DELETE FROM cluster WHERE id = ?')->execute([$clusterId]);
    }

    private function findImportConflict(string $clusterId, string $shortuid, string $pkey): ?string
    {
        $byId = DB::table('cluster')->where('id', $clusterId)->first(['id']);
        if ($byId !== null) {
            return "id={$clusterId}";
        }
        $byShort = DB::table('cluster')->where('shortuid', $shortuid)->first(['id']);
        if ($byShort !== null) {
            return "shortuid={$shortuid}";
        }
        if ($pkey !== '' && $pkey !== 'default') {
            $byPkey = DB::table('cluster')->where('pkey', $pkey)->first(['id']);
            if ($byPkey !== null) {
                return "pkey={$pkey}";
            }
        }

        return null;
    }

    private function exportGreetingMedia(string $shortuid, string $destDir): int
    {
        $soundsRoot = rtrim((string) config('pbx3_directory.tenant_sounds_root'), '/');
        $src = "{$soundsRoot}/{$shortuid}";
        if (! is_dir($src)) {
            return 0;
        }

        return $this->copyTree($src, $destDir);
    }

    private function exportRecordingMedia(string $shortuid, string $destDir): int
    {
        $recRoot = rtrim((string) config('pbx3_directory.tenant_recordings_root'), '/');
        if (! is_dir($recRoot)) {
            return 0;
        }

        if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
            return 0;
        }

        $tenantDir = "{$recRoot}/{$shortuid}";
        if (! is_dir($tenantDir)) {
            return 0;
        }

        return $this->copyTree($tenantDir, $destDir);
    }

    /**
     * @return array{greetings: bool, recordings: bool}
     */
    private function installMedia(string $workDir, string $shortuid, string $tenantPkey): array
    {
        $result = ['greetings' => false, 'recordings' => false];
        $soundsRoot = rtrim((string) config('pbx3_directory.tenant_sounds_root'), '/');
        $greetingsSrc = "{$workDir}/media/greetings/{$shortuid}";
        if (is_dir($greetingsSrc)) {
            $dest = "{$soundsRoot}/{$shortuid}";
            [$ok] = pbx3_request_syscmd('/bin/mkdir -p '.escapeshellarg($dest));
            if ($ok !== null) {
                pbx3_request_syscmd('/bin/cp -a '.escapeshellarg($greetingsSrc).'/. '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chown -R asterisk:asterisk '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chmod -R u+rwX,go+rX '.escapeshellarg($dest));
                $result['greetings'] = true;
            }
        }

        $recordingsSrc = "{$workDir}/media/recordings";
        if (is_dir($recordingsSrc)) {
            $recRoot = rtrim((string) config('pbx3_directory.tenant_recordings_root'), '/');
            $dest = "{$recRoot}/{$shortuid}";
            [$ok] = pbx3_request_syscmd('/bin/mkdir -p '.escapeshellarg($dest));
            if ($ok !== null) {
                pbx3_request_syscmd('/bin/cp -a '.escapeshellarg($recordingsSrc).'/. '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chown -R asterisk:asterisk '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chmod -R u+rwX,go+rX '.escapeshellarg($dest));
                $result['recordings'] = true;
            }
        }

        return $result;
    }

    private function createZip(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip: {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = ltrim(substr($path, strlen($sourceDir)), '/');
            $zip->addFile($path, $relative);
        }
        $zip->close();

        if (! is_file($zipPath)) {
            throw new \RuntimeException("Zip was not created: {$zipPath}");
        }
        @chmod($zipPath, 0664);
    }

    private function copyTree(string $src, string $dest): int
    {
        if (! is_dir($dest) && ! mkdir($dest, 0755, true) && ! is_dir($dest)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
                $bytes += filesize($target) ?: 0;
            }
        }

        return $bytes;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        return $this->tableExistsOnConnection($pdo, '', $table);
    }

    /** @param  ''|'tenant_export'|'tenant_import'  $attachedAlias */
    private function tableExistsOnConnection(\PDO $pdo, string $attachedAlias, string $table): bool
    {
        $catalog = $attachedAlias === '' ? 'sqlite_master' : "{$attachedAlias}.sqlite_master";
        $stmt = $pdo->prepare("SELECT 1 FROM {$catalog} WHERE type = 'table' AND name = ? LIMIT 1");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
