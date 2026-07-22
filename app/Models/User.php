<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\ClusterAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'abilities',
        'allowed_clusters',
        'portable',
        'endpoint',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'abilities' => 'array',
            'allowed_clusters' => 'array',
            'portable' => 'boolean',
        ];
    }

    public function isAdminAbility(): bool
    {
        $abilities = $this->abilities;
        if (! is_array($abilities)) {
            return false;
        }

        return in_array('admin', $abilities, true);
    }

    /**
     * Allowed cluster shortuids (resolved from stored pkeys/shortuids/ids).
     *
     * @return list<string>
     */
    public function allowedClusterShortuids(): array
    {
        return ClusterAccess::resolveShortuids(
            is_array($this->allowed_clusters) ? $this->allowed_clusters : null
        );
    }

    /**
     * Values safe for whereIn on cluster/accountcode columns (shortuid + pkey + id aliases).
     *
     * @return list<string>
     */
    public function allowedClusterScopeValues(): array
    {
        return ClusterAccess::expandAliases($this->allowedClusterShortuids());
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function assertClusterAllowed(?string $identifier): void
    {
        if ($this->isAdminAbility()) {
            return;
        }

        if (! ClusterAccess::userMayAccessCluster($this, $identifier)) {
            abort(403, 'You do not have permission to access this cluster');
        }
    }

    /**
     * Eloquent scope: restrict to rows whose $column is in this user's allowed clusters.
     * Admins are unscoped. Empty allowed_clusters yields no rows.
     *
     * @param  Builder<static>|Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function scopeQueryForAllowedClusters(Builder $query, string $column = 'cluster'): Builder
    {
        if ($this->isAdminAbility()) {
            return $query;
        }

        $scope = $this->allowedClusterScopeValues();
        if ($scope === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $scope);
    }
}
