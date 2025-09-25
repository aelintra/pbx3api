<?php

// Should be renamed endpoints
// Should be throttled by cluster name

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Application;
use App\Models\IpPhone;
use App\Models\IvrMenu;
use App\Models\Queue;
use App\Models\Speed;
use App\Models\Trunk;

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

        $inboundRoutes = [
            'CustomApps' => Application::pluck('pkey')->toArray(),
            'Extensions' => IpPhone::pluck('pkey')->toArray(),
            'IVRs' => IvrMenu::pluck('pkey')->toArray(),
            'Queues' => Queue::pluck('pkey')->toArray(),
//            'RingGroups' => Speed::pluck('pkey')->toArray(),
            'Trunks' => Trunk::where('technology', 'SIP')
                ->orWhere('technology', 'IAX2')
                ->pluck('pkey')
                ->toArray()
        ];

				


/*		
		$conferences = array();
		$handle = fopen("/etc/asterisk/pbx3_meetme.conf", "r") or die('Could not read file!');
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
