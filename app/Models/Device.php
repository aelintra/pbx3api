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

    protected $guarded = [
        'z_created',
        'z_updated',
        'z_updater',
    ];
}
