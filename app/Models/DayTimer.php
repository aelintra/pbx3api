<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DayTimer extends Model
{
    //
    protected $table = 'dateseg';
//    protected $primaryKey = 'IPphone_pkey';
//    protected $keyType = 'string';
//    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'id' => null,
        'pkey' => null,
        'cluster' => 'default',
        'datemonth' => '*',
        'dayofweek' => '*',
        'desc' => null,
        'month' => '*',
        'state' => 'IDLE',
        'timespan' => '*'

    ];

    // none user updateable columns
    protected $guarded = [

	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
//    'pkey',

    ];
}
