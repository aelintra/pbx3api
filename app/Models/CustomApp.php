<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomApp extends Model
{
    //
    protected $table = 'appl';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [

    'cluster' => 'default',
    'desc' => null,
    'extcode' => null,
    'name' => null,
    'span' => 'Neither',
    'striptags' => 'NO'

    ];

    // none user updateable columns
    protected $guarded = [
    'name',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'name'

    ];
}
