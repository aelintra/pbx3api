<?php

// Should be renamed endpoints
// Should be throttled by cluster name

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;

class DestinationController extends Controller
{
    //
    /**
 * Return Endpont Index in a keyed array
 *
 * ToDo - Conferences
 * 		- Proper Mailbox render using really existing mailboxes
 * 
 * @return Sysglobals
 */
    public function index () {

    	$inboundRoutes = array();
		

		$appl = DB::table('appl')->select('pkey')->get();
		foreach ($appl as $value)  {
			$inboundRoutes['CustomApps'][] =  $value->pkey;

		}	

		$ipphone = DB::table('ipphone')->select('pkey')->get();
		foreach ($ipphone as  $value)  {
			$inboundRoutes['Extensions'][] =  $value->pkey;
		}

		$ivrmenu = DB::table('ivrmenu')->select('pkey')->get();
		foreach ($ivrmenu as  $value)  {
			$inboundRoutes['IVRs'][] =  $value->pkey;
		}

		$queue = DB::table('queue')->select('pkey')->get();
		foreach ($queue as  $value)  {
			$inboundRoutes['Queues'][] =  $value->pkey;
		}					
		 
		$speed = DB::table('speed')->select('pkey')->get();
		foreach ($speed as $value)  {
			$inboundRoutes['RingGroups'][] =  $value->pkey;

		}	

		$trunk = DB::table('lineio')
				->select('pkey','technology')
				->where ('technology', '=', 'SIP')
				->orWhere ('technology', '=', 'IAX2')
				->get();
		foreach ($trunk as $value)  {
				$inboundRoutes['Trunks'][] =  $value->pkey;
		}
				


/*		
		$conferences = array();
		$handle = fopen("/etc/asterisk/sark_meetme.conf", "r") or die('Could not read file!');
// get conference room list
		while (!feof($handle)) {		
			$row = trim(fgets($handle));		
			if (preg_match (" /^;/ ", $row)) {
				continue;
			}		
			if (preg_match (" /^conf\s*=>\s*(\d{3,4})/ ",$row,$matches)) {
				array_push ($conferences,$matches[1]);
			}				
		}
		if (is_array($conferences)) {
			foreach ($conferences as $value)  {
				$inboundRoutes['CONF ROOMS'][] = $value;
		}
	}	
*/			
		return response()->json($inboundRoutes, 200);
	}


}
