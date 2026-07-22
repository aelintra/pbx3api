<?php

namespace App\Support;

use App\Models\User;

/**
 * Helpers for instance user cluster scope (allowed_clusters).
 */
class ClusterAccess
{
    /**
     * Resolve a list of cluster identifiers (pkey/shortuid/id) to unique shortuids.
     * Falls back to the raw identifier when the cluster table is unavailable or the
     * row is missing (stored shortuids still match for scope checks).
     *
     * @param  list<mixed>|null  $identifiers
     * @return list<string>
     */
    public static function resolveShortuids(?array $identifiers): array
    {
        if ($identifiers === null || $identifiers === []) {
            return [];
        }

        $out = [];
        foreach ($identifiers as $id) {
            if (! is_string($id) && ! is_numeric($id)) {
                continue;
            }
            $raw = (string) $id;
            if ($raw === '') {
                continue;
            }
            try {
                $short = cluster_identifier_to_shortuid($raw);
                $out[$short !== null && $short !== '' ? $short : $raw] = true;
            } catch (\Throwable) {
                $out[$raw] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Expand identifiers to every known alias (pkey, shortuid, id) for whereIn filters.
     * CDR accountcode and legacy cluster columns may store pkey or shortuid.
     *
     * @param  list<string>  $identifiers
     * @return list<string>
     */
    public static function expandAliases(array $identifiers): array
    {
        $out = [];
        foreach ($identifiers as $id) {
            try {
                foreach (cluster_identifier_aliases($id) as $alias) {
                    $out[(string) $alias] = true;
                }
            } catch (\Throwable) {
                $out[(string) $id] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Whether $identifier is within the user's allowed_clusters (admins always true).
     */
    public static function userMayAccessCluster(User $user, ?string $identifier): bool
    {
        if ($user->isAdminAbility()) {
            return true;
        }

        if ($identifier === null || $identifier === '') {
            return false;
        }

        $allowed = $user->allowedClusterShortuids();
        if ($allowed === []) {
            return false;
        }

        $candidates = [(string) $identifier];
        try {
            $short = cluster_identifier_to_shortuid($identifier);
            if ($short !== null && $short !== '') {
                $candidates[] = $short;
            }
        } catch (\Throwable) {
            // no DB — compare raw only
        }

        foreach ($candidates as $c) {
            if (in_array($c, $allowed, true)) {
                return true;
            }
        }

        // Also allow if any expanded alias of an allowed cluster matches
        foreach ($allowed as $a) {
            try {
                if (in_array($identifier, cluster_identifier_aliases($a), true)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }
}
