<?php

namespace App\Http\Controllers;

use App\SysCommand;
use Illuminate\Http\Request;
use Response;
use Validator;
use Log;
use App\CustomClasses\Ami;
use Illuminate\Support\Facades\Route;

class AstAmiController extends Controller



/**
 * AMI Queries
 * 
 * 
 * 
 */
{   
        protected $eventList = [
                'Agents', 
                'ConfbridgeList',
                'ConfbridgeListRooms',        
                'CoreShowChannels',
                'DeviceStateList',                
                'ExtensionStateList',
                'IAXpeers',
                'IAXregistry',
                'ListCommands',
                'QueueStatus',  
                'QueueSummary',                 
                'SIPpeers', 
                'SIPshowregistry',
                'Status',
                'VoicemailUsersList'
            ];

        protected $eventItem = [
/*
                'ConfbridgeList' => [
                    'Conference' => null
                    ],
*/
                'ExtensionState' => [
                    'Exten' => null,
                    'Context' => 'extensions',
                ],
                'MailboxCount' => [
                    'Mailbox' => null
                ],
                'MailboxStatus' => [
                    'Mailbox' => null
                ],               
                'QueueStatus' => [
                    'Queue' => null,
                    'Member' => null
                ],
                'QueueSummary' => [
                    'Queue' => null
                ],
                'SIPshowpeer' => [
                    'Peer' => null
                ]
            ];

        protected $putItem =  [
                'Reload' => null
        ];

        private $updateableColumns = [
        ];      

/**
 * index list actions
 * method GET
 * @return json actions list]
 */
    public function index () {

        $array_index = array();
        $array_index['GetEventList'] = $this->eventList;
        $array_index['GetEventItem'] = $this->eventItem;
        $array_index['PutItem'] = $this->putItem;

    	return response()->json($array_index,200);

    } 

    public function originate (Request $request) { 

        $validator = Validator::make($request->all(),[
            'target' => 'required|numeric', 
            'caller' => 'required|numeric',
            'context',
            'clid' => 'numeric'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $amiHandle = get_ami_handle();

        $amirets = $amiHandle->originateCall(
                $request->target, 
                'Local/' . $request->caller . '@internal',
                'internal', 
                $request->clid
        );      
        $amiHandle->logout();
        return response()->json(['message' => 'Request Sent'],200);      
    }

    public function dbget (Request $request) {   
        $amiHandle = get_ami_handle();
        $amirets [$request->key] = $amiHandle->getDB($request->id,$request->key);
        $amiHandle->logout();
        return response()->json($amirets,200);       
    } 

    public function dbput (Request $request) {   
        $amiHandle = get_ami_handle();
        $amirets [$request->key] = $amiHandle->putDB($request->id,$request->key,$request->value);
        $amiHandle->logout();
        return response()->json($amirets,200);       
    }

    public function dbdel (Request $request) {   
        $amiHandle = get_ami_handle();
        $amirets [$request->key] = $amiHandle->delDB($request->id,$request->key);
        $amiHandle->logout();
        return response()->json($amirets,200);       
    }    

    public function reload (Request $request) {   
        $amiHandle = get_ami_handle();
        $amiArgs = "Action: Reload\r\n";
        $amirets [$request->key] = $amiHandle->amiQuery($amiArgs);
        $amiHandle->logout();
        return response()->json(['Response' => 'Reload sent'],200); 
    } 
/*
    public function confbridgelist (Request $request) {      
        $this->eventItem['ConfbridgeList']['Conference'] = $request->id;
        return $this->getinstance($request,'ConfbridgeList');
    }         
*/
    public function extensionstate (Request $request) {
        $this->eventItem['ExtensionState']['Exten'] = $request->id;
        if (isset($request->Context)) {
           $this->eventItem['ExtensionState']['Context'] = $request->Context; 
        }
        return $this->getinstance($request,'ExtensionState');
    }

    public function mailboxcount (Request $request) {   
        $this->eventItem['MailboxCount']['Mailbox'] = $request->id . "@default";
        return $this->getinstance($request,'MailboxCount');
    }

    public function mailboxstatus (Request $request) {      
        $this->eventItem['MailboxStatus']['Mailbox'] = $request->id;
        return $this->getinstance($request,'MailboxStatus');
    } 

    public function queuestatus (Request $request) {   
        $this->eventItem['QueueStatus']['Queue'] = $request->id;
        return $this->getinstance($request,'QueueStatus');
    } 

    public function queuesummary (Request $request) {      
        $this->eventItem['QueueStatus']['Queue'] = $request->id;
        return $this->getinstance($request,'QueueStatus');
    } 

     public function sipshowpeer (Request $request) {
        $this->eventItem['SIPshowpeer']['Peer'] = $request->id;
        return $this->getinstance($request,'SIPshowpeer');
    }   

     public function coresettings (Request $request) {
        return $this->getinstance($request,'CoreSettings');
    }  

    public function corestatus (Request $request) {
        return $this->getinstance($request,'CoreStatus');
    }  

    private function check_if_id_present ($request) {
        if (empty( $request->id)) {
            return response()->json(['message' => 'No Key field in route'],404)->send();
        }
        return false;
    }  

/**
 * getinstance retrieve a single instance from the AMI
 * 
 * @param  Request $request 
 * @param  string  $action  AMI Action command
 * @return json             AMI response
 * 
 */
    private function getinstance (Request $request, $action) {
        if (! pbx_is_running() ) {
            return response()->json(['message' => 'PBX not running'],503);
        } 

        $amiArgs = "Action: " . $action . "\r\n";

        if ( !empty($this->eventItem[$action] )) {
            foreach ($this->eventItem[$action] as $key => $value) {
                $amiArgs .= "$key: $value\r\n";
            }
        }

        $amiHandle = get_ami_handle();
        $amirets = $amiHandle->amiQuery($amiArgs);
        $amiHandle->logout();
//print_r($amirets);
        $amiArray=array();
        $lines = explode("\r\n",$amirets);  
        foreach ($lines as $line) {
        // ignore lines that aren't couplets
            if (!preg_match(' /:/ ',$line)) { 
                continue;
            }
        // parse the couplet    
            $couplet = explode(': ', $line);
            $amiArray [$couplet[0]] = $couplet[1];       
        }

        return Response::json($amiArray,200);
    }

           
    public function getlist (Request $request) {

        if (! pbx_is_running() ) {
            return response()->json(['message' => 'PBX not running'],503);
        }         

        if (!in_array($request->action,$this->eventList)) {
            return response()->json(['message' => 'AMI Action invalid or unsupported'],404);
        }

        $amiArgs = "Action: " . $request->action . "\r\n";

/*
    Corner case of ConfbridgeList.   It is a list but also requires a Conference room parameter.
 */
        if (preg_match("/ConfbridgeList$/", $request->action )) {
            $amiArgs .= "Conference: " . $request->id . "\r\n";
        }

        $amiHandle = get_ami_handle();
        $amirets = $amiHandle->amiQuery($amiArgs);
        $amiHandle->logout();

//print_r($amirets);
//       return;
// Check for empty return        
        if (preg_match(' /ListItems: 0/ ', $amirets))  {
            return response()->json(['message' => 'Empty Object set returned from AMI'],404);
        }

//        $amiArray = $this->build_list_array($request, $amirets);

        return Response::json($this->build_list_array($request, $amirets),200);
    }


    private function build_list_array($request, $amirets) {
/*
 * build an array from the AMI output
 */ 
    $list_array=array();
    $listItemKey = null;

    $item_array=array();

    $lines = explode("\r\n",$amirets);  
//    print_r($lines);
    
    foreach ($lines as $line) {
// ignore lines that aren't couplets
        if (!preg_match(' /:/ ',$line)) { 
                continue;
        }
        if (preg_match(' /^Event:/ ',$line)) { 
            if (!empty($item_array)) {
                array_push($list_array, $item_array);
                unset ($item_array);
            }
        }

        
// parse the couplet    
        $couplet = explode(': ', $line);
        
// ignore events and ListItems
        if  ($couplet[0] == 'ListItems' || $couplet[0] == 'EventList') {
            continue;
        }
        
        $item_array [$couplet[0]] = $couplet[1];

    }

    return $list_array; 

}


}
