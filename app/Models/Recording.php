<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Call recording catalog row (Phase R1.5+).
 * Schema: sqlite_create_tenant.sql recordings.
 */
class Recording extends Model
{
    protected $table = 'recordings';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'cluster',
        'epoch',
        'callerid',
        'dnid',
        'queue',
        'extension',
        'filename',
        'local_path',
        's3_key',
        'location',
        'filesize',
        'deleted_at',
        'z_created',
        'z_updated',
        'z_updater',
    ];
}
