<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomApp extends Model
{
    protected $table = 'appl';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'cluster' => 'default',
        'span' => 'Neither',
        'striptags' => 'NO',
    ];

    /** Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql appl. id/shortuid set on create in controller. */
    protected $fillable = [
        'pkey',
        'active',
        'cluster',
        'description',
        'directdial',
        'extcode',
        'name',
        'cname',
        'span',
        'striptags',
    ];

    /** name is deprecated (schema: use cname instead); hidden from JSON. */
    protected $hidden = [
        'name',
    ];

    /**
     * Resolve route model binding by shortuid (globally unique) then pkey for backward compatibility.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $model = static::where('shortuid', $value)->first();
        if ($model) {
            return $model;
        }
        return static::where('pkey', $value)->first();
    }
}
