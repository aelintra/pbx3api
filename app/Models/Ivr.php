<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ivr extends Model
{
    //
    protected $table = 'ivrmenu';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
	// id and shortuid not set here so Eloquent hydrates them from DB like Tenant/Trunk/Extension
	'pkey' => null,
	'alert0' => null,
	'alert1' => null,
	'alert10' => null,
	'alert11' => null,
	'alert2' => null,
	'alert3' => null,
	'alert4' => null,
	'alert5' => null,
	'alert6' => null,
	'alert7' => null,
	'alert8' => null,
	'alert9' => null,
	'description' => null,
	'cluster' => null,
	'greetnum' => null,
	'listenforext' => 'NO',
	'name' => null,
	'option0' => 'None',
	'option1' => 'None',
	'option10' => 'None',
	'option11' => 'None',
	'option2' => 'None',
	'option3' => 'None',
	'option4' => 'None',
	'option5' => 'None',
	'option6' => 'None',
	'option7' => 'None',
	'option8' => 'None',
	'option9' => 'None',
	'tag0' => null,
	'tag1' => null,
	'tag10' => null,
	'tag11' => null,
	'tag2' => null,
	'tag3' => null,
	'tag4' => null,
	'tag5' => null,
	'tag6' => null,
	'tag7' => null,
	'tag8' => null,
	'tag9' => null,
	'timeout' => 'operator'

    ];



    // none user updateable columns
    protected $guarded = [

	'z_created',
	'z_updated',
	'z_updater'
    ];

    // Hidden from JSON (none; id and shortuid shown in list/detail like Trunk/InboundRoute)
    protected $hidden = [];
}
