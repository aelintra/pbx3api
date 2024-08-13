<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Response;
use Validator;

class FirewallController extends Controller
{

	private $updateableColumns = [];

    //
/**
 * Return ipv4 Firewall Index 
 * 
 * @return rules
 */
    public function ipv4 () {

        $file = file("/etc/shorewall/sark_rules",FILE_IGNORE_NEW_LINES);
        return response()->json($this->set_output ($file),200);
    }

/**
 * Return ipv6 Firewall Index 
 * 
 * @return rules
 */
    public function ipv6 () {

        $file = file("/etc/shorewall6/sark_rules6",FILE_IGNORE_NEW_LINES);
        return response()->json($this->set_output ($file),200);
    } 

/**
 * [set_output build an array from the firewall rules]
 * @param [Array] $ruleArray
 */
    private function set_output ($file) {
        $ruleArray =array();
        foreach ($file as $line){
            $ruleArray[ "rules" ][] = $line;
        }
        return($ruleArray); 
    }

 /** 
 *
 * save new ipv4 rules
 * @param $request  
 * 
 * @return 200
 */
    public function ipv4save(Request $request) {

    // Validate         
        $validator = Validator::make($request->all(),[         
            'rules' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $tempFileName = $this->set_new_rules($request);

        shell_exec("/bin/mv /tmp/$tempFileName /etc/shorewall/sark_rules");

		return response()->json(['message' => "saved sark_rules"], 200);
    }  

/**
 *  
 * save new rules to /tmp
 * @param $request  
 * 
 * @return 200
 */
    public function ipv6save(Request $request) {

// Validate         
        $validator = Validator::make($request->all(),[         
            'rules' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $tempFileName = $this->set_new_rules($request);

        shell_exec("/bin/mv /tmp/$tempFileName /etc/shorewall6/sark_rules6");

        return response()->json(['message' => "saved sark_rules"], 200);
    }

/**
 *  
 * save new rules to /tmp
 * @param $request
 * 
 * @return 200
 */
    private function set_new_rules($request) {

        $fname = "rules_" . time() . ".txt";
        $file= fopen("/tmp/$fname","w") or die("Unable to open file!");;

        foreach($request->rules as $rule) {
            fwrite($file, $rule . PHP_EOL);
        }   

        fclose($file);

        return $fname;
    }   

/**
 * 
 * restart the ipv4 firewall
 * @param  null
 * @return msg
 */
    public function ipv4restart() {

        $rc = `sudo /sbin/shorewall check 2>&1`;

        if (! strchr($rc, 'ERROR')) {
            $rc = `sudo /sbin/shorewall restart`;
            return response()->json(['message' => "Shorewall restarted OK"], 200);
        }
        $errorLines = explode("\n", $rc);
        return response()->json($errorLines, 500);
    }

/**
 * 
 * restart the ipv4 firewall
 * @param  null
 * @return msg
 */
    public function ipv6restart() {

        $rc = `sudo /sbin/shorewall6 check 2>&1`;

        if (! strchr($rc, 'ERROR')) {
            $rc = `sudo /sbin/shorewall6 restart`;
            return response()->json(['message' => "Shorewall restarted OK"], 200);
        }
        $errorLines = explode("\n", $rc);
        return response()->json($errorLines, 500);
    }

}
