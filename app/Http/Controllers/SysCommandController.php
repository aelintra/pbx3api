<?php

namespace App\Http\Controllers;

use App\SysCommand;
use Illuminate\Http\Request;
use Response;
use Validator;

class SysCommandController extends Controller

/**
 * SysCommands
 * 
 * Just GET
 * 
 */
{
    //
    //
    //Route::get('syscommands/commit', 'SysCommandController@commit');

    private $commands = [
    	'commit' => 'null',
    	'reboot' => 'null',
    	'pbxstart' => 'null',
    	'pbxstop' => 'null',
        'pbxrunstate' => 'returns PBX state (boolean)'
    ];
/**
 * Return SysCommand Index in pkey order asc
 * @return SysCommands
 */
    public function index () {

    	return response()->json($this->commands,200);

    } 

    public function commit () {

        `/bin/sh /opt/sark/scripts/srkgenAst`;
         return response()->json(['message' => 'System Commit issued'],200);

    } 

    public function reboot () {
       
        `sudo /sbin/reboot`;
        return response()->json(['message' => 'Reboot issued'],200);
    } 

    public function start () {

        if  (`/bin/ps -e | /bin/grep asterisk | /bin/grep -v grep`) {
            return response()->json(['message' => 'PBX already running'],503);
        }

        `sudo /bin/systemctl start asterisk`;
        return response()->json(['message' => 'PBX started'],200);

    } 

    public function stop () {

        if  (!`/bin/ps -e | /bin/grep asterisk | /bin/grep -v grep`) {
            return response()->json(['message' => 'PBX not running'],503);
        }

        `sudo /bin/systemctl stop asterisk`;
        return response()->json(['message' => 'PBX stopped'],200);

    }

    public function pbxrunstate () {

        if  (`/bin/ps -e | /bin/grep asterisk | /bin/grep -v grep`) {
            return response()->json(['pbxrunstate' => True],200);
        }
        return response()->json(['pbxrunstate' => False],200);
    }             


}
