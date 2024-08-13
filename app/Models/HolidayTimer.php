<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayTimer extends Model
{
    //
    protected $table = 'holiday';
    public $timestamps = false;

    protected $attributes = [
        'id' => null,
        'pkey' => null,
        'cluster' => 'default',
        'route' => 'None',
        'routeclass' => '0',
        'stime' => null,
        'etime' => null

    ];

    // none user updateable columns
    protected $guarded = [

	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'pkey',
//    'routeclass',


    ];
}
