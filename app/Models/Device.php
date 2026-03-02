<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Device table: provisioning templates (instance-scoped; pkey-only, no id/shortuid).
 *
 * @see pbx3/db/db_sql/sqlite_create_instance.sql
 */
class Device extends Model
{
    protected $table = 'device';

    protected $primaryKey = 'pkey';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** Real device columns (z_* are system-managed, not mass-assigned). */
    protected $fillable = [
        'pkey',
        'blfkeyname',
        'blfkeys',
        'desc',
        'device',
        'fkeys',
        'imageurl',
        'legacy',
        'noproxy',
        'owner',
        'pkeys',
        'provision',
        'sipiaxfriend',
        'technology',
        'tftpname',
        'zapdevfixed',
    ];

    /** Omit from JSON: device (never from client), deprecated, and updateable-but-hidden. */
    protected $hidden = [
        'device',
        'fkeys',
        'imageurl',
        'noproxy',
        'sipiaxfriend',
        'tftpname',
        'zapdevfixed',
    ];
}
