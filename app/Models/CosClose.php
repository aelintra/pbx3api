<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CosClose extends Model
{
    //
    protected $table = 'IPphoneCOSclosed';
    protected $primaryKey = 'IPphone_pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [

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
