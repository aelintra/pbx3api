<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassOfService extends Model
{
    //
    protected $table = 'cos';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [

        'active' => 'NO',
        'defaultclosed' => 'NO',
        'defaultopen' => 'NO',
        'description' => null,
        'dialplan' => null,
        'orideclosed'=> 'NO',
        'orideopen' => 'NO'

    ];

    // none user updateable columns
    protected $guarded = [

	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [


    ];
}
