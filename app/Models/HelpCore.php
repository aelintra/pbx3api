<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * tt_help_core: UI/help messages (instance-scoped; pkey-only, no id/shortuid).
 *
 * @see pbx3/db/db_sql/sqlite_create_instance.sql
 */
class HelpCore extends Model
{
    protected $table = 'tt_help_core';

    protected $primaryKey = 'pkey';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** Real columns (z_* are system-managed, not mass-assigned). name is deprecated (use cname); excluded from fillable. */
    protected $fillable = [
        'pkey',
        'displayname',
        'htext',
        'cname',
    ];

    /** name: deprecated per schema. cname: hidden from API response. */
    protected $hidden = [
        'name',
        'cname',
    ];
}
