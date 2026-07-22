<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\ClusterAccess;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Apply allowed_clusters row scope for non-admin instance users.
 */
trait EnforcesClusterScope
{
    protected function clusterScopeUser(): ?User
    {
        return request()->user('sanctum') ?? auth('sanctum')->user();
    }

    /**
     * Scope an Eloquent query to the current user's allowed clusters (no-op for admin).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyClusterScope(Builder $query, string $column = 'cluster'): Builder
    {
        $user = $this->clusterScopeUser();
        if ($user === null || $user->isAdminAbility()) {
            return $query;
        }

        $scope = $user->allowedClusterScopeValues();
        if ($scope === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $scope);
    }

    /**
     * @throws HttpException 401/403
     */
    protected function assertClusterAllowed(?string $identifier): void
    {
        $user = $this->clusterScopeUser();
        if ($user === null) {
            abort(401, 'Authentication required');
        }

        $user->assertClusterAllowed($identifier);
    }

    /**
     * @param  object{cluster?: mixed}  $model
     *
     * @throws HttpException 401/403
     */
    protected function assertModelClusterAllowed(object $model, string $attribute = 'cluster'): void
    {
        $raw = $model->{$attribute} ?? null;
        $this->assertClusterAllowed($raw !== null ? (string) $raw : null);
    }

    /**
     * Clamp a request cluster identifier for create/update; 403 if out of scope.
     *
     * @return string|null Resolved shortuid (or null if identifier missing/invalid — caller validates)
     */
    protected function requireAllowedClusterShortuid(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $short = cluster_identifier_to_shortuid($identifier);
        if ($short === null) {
            return null;
        }

        $this->assertClusterAllowed($short);

        return $short;
    }

    /**
     * Allowed cluster identifiers for CDR accountcode / recordings tenant filters (aliases expanded).
     *
     * @return list<string>|null  null = admin (no clamp); empty = non-admin with no clusters
     */
    protected function clampedClusterScopeOrNull(): ?array
    {
        $user = $this->clusterScopeUser();
        if ($user === null || $user->isAdminAbility()) {
            return null;
        }

        return $user->allowedClusterScopeValues();
    }

    /**
     * Ensure a requested accountcode/tenant is inside allowed scope (non-admin).
     *
     * @throws HttpException 403
     */
    protected function assertRequestedClusterInScope(?string $identifier): void
    {
        $scope = $this->clampedClusterScopeOrNull();
        if ($scope === null) {
            return;
        }

        if ($identifier === null || $identifier === '') {
            return;
        }

        if (! ClusterAccess::userMayAccessCluster($this->clusterScopeUser(), $identifier)) {
            abort(403, 'You do not have permission to access this cluster');
        }
    }
}
