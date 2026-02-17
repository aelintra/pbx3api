<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

//use App\SysCommand;
//use Illuminate\Http\Request;
//use Illuminate\Support\Facades\Response;
//use Illuminate\Support\Facades\Validator;

class SysCommandController extends Controller

/**
 * SysCommands
 *
 * GET commitstatus = dirty state (Save vs Commit).
 * GET commit = run generator + Asterisk reload, clear dirty.
 */
{
    private $commands = [
        'commit' => 'run generator and Asterisk reload',
        'commitstatus' => 'returns { dirty: boolean }',
        'reboot' => 'null',
        'pbxstart' => 'null',
        'pbxstop' => 'null',
        'pbxrunstate' => 'returns PBX state (boolean)'
    ];

    /**
     * Return SysCommand index.
     */
    public function index () {
        return response()->json($this->commands, 200);
    }

    /**
     * GET commit status: true if there are uncommitted DB changes (Commit button should be red).
     */
    public function commitstatus () {
        try {
            $row = DB::table('globals')->first(['mycommit']);
            $dirty = isset($row->mycommit) && strtoupper((string) $row->mycommit) === 'YES';
            return response()->json(['dirty' => $dirty], 200);
        } catch (\Throwable $e) {
            return response()->json(['dirty' => false], 200);
        }
    }

    /**
     * Run Asterisk config generator then reload. Clears dirty state (mycommit = NO).
     */
    public function commit () {
        $genAst = env('PBX3_GENAST_SCRIPT', '/opt/pbx3/scripts/genAst.sh');
        $asterisk = env('PBX3_ASTERISK_EXEC', '/usr/sbin/asterisk');
        if (is_readable($genAst)) {
            exec('/bin/sh ' . escapeshellarg($genAst) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                return response()->json(['message' => 'Generator failed', 'detail' => implode("\n", $out)], 502);
            }
        }
        exec(escapeshellarg($asterisk) . ' -rx ' . escapeshellarg('core reload') . ' 2>&1', $relout, $relcode);
        try {
            DB::table('globals')->update(['mycommit' => 'NO']);
        } catch (\Throwable $e) {
            // ignore
        }
        return response()->json(['message' => 'Commit completed'], 200);
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
