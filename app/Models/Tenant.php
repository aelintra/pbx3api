<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'cluster';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

/*    
    protected $fillable = [
      'pkey',
      'description',
    	'abstimeout',
    	'chanmax',
    	'masteroclo'
    ];
*/    
    
    // none user updateable columns
/*    
    protected $guarded = [
      'id',  
      'name',
      'oclo', 
      'routeoverride',   	
    	'z_created',
    	'z_updated'   	
    ];
*/

    // hidden columns (mostly no longer used)
    protected $hidden = [
		
    "blind_busy",
    "bounce_alert",
    'callgroup',
    "camp_on_q_onoff",
    "camp_on_q_opt",
    'cname',
    'devicerec',
    'dynamicfeatures',
    'emailalert',
    'EXTBLKLST',
    'ext_lim',
		'ext_len',
    'include',
    'mixmonitor',
    'monitor_stage',
    'monitor_type',
    'name',
    'number_range_regex',
    'oclo',
    'padminpass',
    'puserpass',    		
    'callgroup',
    'include',
    'pickupgroup',
    'VDELAY',
    'vxt'

    ];
}
