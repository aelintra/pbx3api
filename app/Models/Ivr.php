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
	'routeclass0' => 0,
	'routeclass1' => 0,
	'routeclass10' => 0,
	'routeclass11' => 0,
	'routeclass2' => 0,
	'routeclass3' => 0,
	'routeclass4' => 0,
	'routeclass5' => 0,
	'routeclass6' => 0,
	'routeclass7' => 0,
	'routeclass8' => 0,
	'routeclass9' => 0,
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
	'timeout' => 'operator',					
	'timeoutrouteclass' => 100

    ];



    // none user updateable columns
    protected $guarded = [

	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'id',
    "routeclass0",
    "routeclass1",
    "routeclass10",
    "routeclass11",
    "routeclass2",
    "routeclass3",
    "routeclass4",
    "routeclass5",
    "routeclass6",
    "routeclass7",
    "routeclass8",
    "routeclass9"
    ];
}
