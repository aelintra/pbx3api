<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ivr extends Model
{
    //
    protected $table = 'ivrmenu';
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'listenforext' => 'NO',
        'cluster' => 'default',
        'timeout' => '30',
    ];

    /**
     * Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql ivrmenu.
     * Excludes id, shortuid (set on create), z_* (system-only).
     */
    protected $fillable = [
        'pkey',
        'active',
        'alert0', 'alert1', 'alert2', 'alert3', 'alert4', 'alert5',
        'alert6', 'alert7', 'alert8', 'alert9', 'alert10', 'alert11',
        'cluster',
        'cname',
        'description',
        'greetnum',
        'listenforext',
        'name',
        'option0', 'option1', 'option2', 'option3', 'option4', 'option5',
        'option6', 'option7', 'option8', 'option9', 'option10', 'option11',
        'tag0', 'tag1', 'tag2', 'tag3', 'tag4', 'tag5',
        'tag6', 'tag7', 'tag8', 'tag9', 'tag10', 'tag11',
        'timeout',
    ];

    /** name is deprecated (use cname). */
    protected $hidden = [
        'name',
    ];

	/**
	 * Resolve route model binding by shortuid (globally unique) instead of pkey (tenant-scoped).
	 * Falls back to pkey for backward compatibility if shortuid not found.
	 */
	public function resolveRouteBinding($value, $field = null)
	{
		// Try shortuid first (globally unique)
		$model = static::where('shortuid', $value)->first();
		if ($model) {
			return $model;
		}
		
		// Fallback to pkey for backward compatibility (though pkey is tenant-scoped and may be ambiguous)
		return static::where('pkey', $value)->first();
	}
}
