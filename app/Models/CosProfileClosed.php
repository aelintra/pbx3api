<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CosProfileClosed extends Model
{
    protected $table = 'cos_profile_closed';

    public $incrementing = false;

    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'cluster' => 'default',
    ];

    protected $fillable = [
        'id',
        'cluster',
        'active',
        'profile_pkey',
        'cos_pkey',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];
}
