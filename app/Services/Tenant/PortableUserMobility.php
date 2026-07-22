<?php

namespace App\Services\Tenant;

use App\Models\User;
use App\Support\ClusterAccess;

/**
 * Pack / unpack portable customer users with a tenant move (privileges P4).
 *
 * Laravel `users` live in instance SQL (not sqlite_create_tenant.sql), so they travel as
 * portable_users.json in the export zip — not as mini-DB rows.
 *
 * Move policy (first-out):
 * - Export: portable, non-admin users whose allowed_clusters includes this tenant.
 * - Package always scopes allowed_clusters to this tenant shortuid only.
 * - Detach / delete-tenant: single-cluster users deleted (+ tokens); multi-cluster users
 *   have this shortuid stripped from allowed_clusters.
 * - Import: create or merge by email; never overwrite an admin; Sanctum tokens stay local.
 */
class PortableUserMobility
{
    public const JSON_FILENAME = 'portable_users.json';

    public const SCHEMA_VERSION = 1;

    /**
     * Users that travel with this tenant (payload rows for JSON).
     *
     * @return list<array{email: string, name: string, password: string, abilities: list<string>, allowed_clusters: list<string>, portable: bool, endpoint: mixed}>
     */
    public function collectForTenant(string $shortuid): array
    {
        $shortuid = (string) $shortuid;
        if ($shortuid === '') {
            return [];
        }

        $out = [];
        foreach (User::query()->where('portable', true)->orderBy('id')->get() as $user) {
            if ($user->isAdminAbility()) {
                continue;
            }
            $allowed = $user->allowedClusterShortuids();
            if (! in_array($shortuid, $allowed, true)) {
                continue;
            }
            $abilities = array_values(array_filter(
                is_array($user->abilities) ? $user->abilities : [],
                static fn ($a) => is_string($a) && $a !== '' && $a !== 'admin'
            ));
            if ($abilities === []) {
                $abilities = ['tenant'];
            }

            $out[] = [
                'email' => (string) $user->email,
                'name' => (string) $user->name,
                'password' => (string) $user->getRawOriginal('password'),
                'abilities' => $abilities,
                'allowed_clusters' => [$shortuid],
                'portable' => true,
                'endpoint' => $user->endpoint,
            ];
        }

        return $out;
    }

    /**
     * Write portable_users.json into an export work directory.
     *
     * @param  list<array<string, mixed>>  $users
     */
    public function writeExportFile(string $workDir, string $shortuid, array $users): string
    {
        $path = rtrim($workDir, '/').'/'.self::JSON_FILENAME;
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_shortuid' => $shortuid,
            'users' => array_values($users),
        ];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * After packing: remove single-cluster portable users; strip shortuid from multi-cluster.
     *
     * @return array{deleted: int, stripped: int}
     */
    public function detachFromSource(string $shortuid): array
    {
        return $this->removeOrStripForTenant($shortuid);
    }

    /**
     * Same as detach — used when the tenant row is deleted on the source after a move.
     *
     * @return array{deleted: int, stripped: int}
     */
    public function removeOrStripForTenant(string $shortuid): array
    {
        $shortuid = (string) $shortuid;
        $deleted = 0;
        $stripped = 0;
        if ($shortuid === '') {
            return ['deleted' => 0, 'stripped' => 0];
        }

        foreach (User::query()->where('portable', true)->orderBy('id')->get() as $user) {
            if ($user->isAdminAbility()) {
                continue;
            }
            $allowed = $user->allowedClusterShortuids();
            if (! in_array($shortuid, $allowed, true)) {
                continue;
            }
            $remaining = array_values(array_filter($allowed, static fn ($s) => $s !== $shortuid));
            if ($remaining === []) {
                $this->revokeTokensQuietly($user);
                $user->delete();
                $deleted++;
            } else {
                $user->allowed_clusters = $remaining;
                $user->save();
                $this->revokeTokensQuietly($user);
                $stripped++;
            }
        }

        return ['deleted' => $deleted, 'stripped' => $stripped];
    }

    /**
     * Import portable_users.json from an extracted zip work directory.
     *
     * @return array{created: int, updated: int, skipped: list<string>, count: int}
     */
    public function importFromWorkDir(string $workDir, string $destShortuid): array
    {
        $path = rtrim($workDir, '/').'/'.self::JSON_FILENAME;
        if (! is_file($path)) {
            return ['created' => 0, 'updated' => 0, 'skipped' => [], 'count' => 0];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw) || (int) ($raw['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('Unsupported or missing portable_users.json schema_version');
        }

        $users = $raw['users'] ?? [];
        if (! is_array($users)) {
            return ['created' => 0, 'updated' => 0, 'skipped' => [], 'count' => 0];
        }

        return $this->importUsers($users, $destShortuid);
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return array{created: int, updated: int, skipped: list<string>, count: int}
     */
    public function importUsers(array $users, string $destShortuid): array
    {
        $destShortuid = (string) $destShortuid;
        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach ($users as $row) {
            if (! is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = 'invalid email';
                continue;
            }
            $password = (string) ($row['password'] ?? '');
            if ($password === '') {
                $skipped[] = "{$email}: missing password hash";
                continue;
            }

            $abilities = array_values(array_filter(
                is_array($row['abilities'] ?? null) ? $row['abilities'] : [],
                static fn ($a) => is_string($a) && $a !== '' && $a !== 'admin'
            ));
            if ($abilities === []) {
                $abilities = ['tenant'];
            }

            $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existing !== null) {
                if ($existing->isAdminAbility()) {
                    $skipped[] = "{$email}: email owned by instance admin";
                    continue;
                }
                // Merge: restore credentials + ensure this tenant is in scope.
                $existing->name = (string) ($row['name'] ?? $existing->name);
                $existing->forceFill(['password' => $password]);
                $existing->abilities = $abilities;
                $existing->portable = true;
                $merged = ClusterAccess::resolveShortuids(
                    array_merge(
                        is_array($existing->allowed_clusters) ? $existing->allowed_clusters : [],
                        [$destShortuid]
                    )
                );
                if ($merged === []) {
                    $merged = [$destShortuid];
                }
                $existing->allowed_clusters = $merged;
                if (array_key_exists('endpoint', $row)) {
                    $existing->endpoint = $row['endpoint'];
                }
                $existing->save();
                $this->revokeTokensQuietly($existing);
                $updated++;
                continue;
            }

            $user = new User;
            $user->name = (string) ($row['name'] ?? $email);
            $user->email = $email;
            $user->forceFill(['password' => $password]);
            $user->abilities = $abilities;
            $user->allowed_clusters = [$destShortuid];
            $user->portable = true;
            $user->endpoint = $row['endpoint'] ?? null;
            $user->save();
            $created++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'count' => $created + $updated,
        ];
    }

    /**
     * Whether a stored allowed_clusters list includes $shortuid (without requiring cluster table).
     *
     * @param  list<mixed>|null  $allowed
     */
    public static function listIncludesShortuid(?array $allowed, string $shortuid): bool
    {
        if ($allowed === null || $allowed === []) {
            return false;
        }
        foreach (ClusterAccess::resolveShortuids($allowed) as $s) {
            if ($s === $shortuid) {
                return true;
            }
        }
        // Raw match (tests / missing cluster row)
        foreach ($allowed as $id) {
            if ((string) $id === $shortuid) {
                return true;
            }
        }

        return false;
    }

    private function revokeTokensQuietly(User $user): void
    {
        try {
            $user->tokens()->delete();
        } catch (\Throwable) {
            // personal_access_tokens may be absent in minimal test DBs
        }
    }
}
