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

    /**
     * GET sysnotes — read-only system info for Home panel (equivalent to SARK printSysNotes).
     * Returns system, network, and resource data for display; no sensitive or editable fields.
     */
    public function sysnotes()
    {
        $asterisk = env('PBX3_ASTERISK_EXEC', '/usr/sbin/asterisk');

        // Instance = globals.pkey (only one row in globals)
        $instance = null;
        try {
            $g = DB::table('globals')->first();
            if ($g && isset($g->pkey)) {
                $instance = $g->pkey;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $system = [
            'instance' => $instance,
            'distro' => $this->safeShell("lsb_release -ds 2>/dev/null"),
            'asterisk_release' => $this->getAsteriskRelease($asterisk),
            'app_release' => trim(shell_exec("dpkg-query -W -f '\${Version}' pbx3 2>/dev/null") ?: '') ?: null,
            'endpoints_defined' => null,
            'serial' => null,
        ];

        try {
            $extcount = DB::table('ipphone')->count();
            $system['endpoints_defined'] = $extcount;
        } catch (\Throwable $e) {
            $system['endpoints_defined'] = null;
        }

        $network = [
            'hostname' => gethostname() ?: null,
            'local_ip' => $this->getLocalIpV4(),
            'mac' => $this->safeShell("ip route show default 2>/dev/null | head -1 | awk '{for(i=1;i<=NF;i++)if(\$i==\"dev\"){print \$(i+1);exit}}' | xargs -I{} ip link show {} 2>/dev/null | awk '/ether/{print \$2}'"),
            'public_ip' => $this->safeShell("curl -s -m 2 ifconfig.me 2>/dev/null") ?: null,
        ];

        $free = `/usr/bin/free -b 2>/dev/null`;
        $totmem = $freemem = null;
        if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $m)) {
            $totmem = (int) $m[1];
            $freemem = (int) $m[3];
        }
        $df = `/bin/df -k . 2>/dev/null`;
        $diskusage = null;
        if (preg_match('/(\d{1,2})%/', $df, $m)) {
            $diskusage = $m[1] . '%';
        }

        $masteroclo = trim(`$asterisk -rx 'database get STAT OCSTAT' 2>/dev/null`);
        $masteroclo = preg_replace('/^.*:\s*/', '', $masteroclo);
        if (preg_match('/not\s+found/i', $masteroclo) || $masteroclo === '') {
            $masteroclo = 'AUTO';
        }

        $clusteroclo = null;
        try {
            $c = DB::table('cluster')->where('pkey', 'default')->first();
            if ($c && isset($c->oclo)) {
                $clusteroclo = $c->oclo;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $runstate = `/bin/ps -e 2>/dev/null | /bin/grep asterisk | /bin/grep -v grep` ? 'RUNNING' : 'STOPPED';

        $resource = [
            'disk_usage' => $diskusage,
            'ram_total' => $totmem,
            'ram_free' => $freemem,
            'pbx_runstate' => $runstate,
            'masteroclo' => $masteroclo,
            'timer_state' => $clusteroclo,
        ];

        return response()->json([
            'system' => $system,
            'network' => $network,
            'resource' => $resource,
        ], 200);
    }

    private function safeShell($cmd)
    {
        $out = @shell_exec($cmd);
        return $out ? trim(preg_replace('/\s+/', ' ', $out)) : null;
    }

    /**
     * Get Asterisk version (e.g. "21.2.0") via core show version.
     * Uses sudo so www-data can connect to the Asterisk socket (same as old pbx3 AmiHelperClass).
     */
    private function getAsteriskRelease($asterisk)
    {
        $check = shell_exec('/bin/ps -e 2>/dev/null | /bin/grep asterisk | /bin/grep -v grep');
        if (!$check) {
            return null;
        }
        $cmd = 'sudo ' . escapeshellarg($asterisk) . ' -rx ' . escapeshellarg('core show version') . ' 2>/dev/null';
        $ver = shell_exec($cmd);
        if (!$ver) {
            return null;
        }
        $ver = trim($ver);
        if (preg_match('/Asterisk\s+([^\s]+)/', $ver, $m)) {
            $raw = $m[1];
            // Return only major.minor.patch (e.g. 20.6.0 from 20.6.0~dfsg+~cs6.13.40431414-2build5)
            if (preg_match('/^(\d+\.\d+\.\d+)/', $raw, $v)) {
                return $v[1];
            }
            return $raw;
        }
        return strlen($ver) ? $ver : null;
    }

    /**
     * Get local IPv4 (same logic as pbx3 NetHelperClass::get_localIPV4):
     * if globals.staticipv4 is set use that, else first inet from default/UP interface.
     */
    private function getLocalIpV4()
    {
        try {
            $row = DB::table('globals')->first();
            if ($row && !empty($row->staticipv4)) {
                return trim($row->staticipv4);
            }
        } catch (\Throwable $e) {
            // fall through to shell
        }
        $firstUp = trim(shell_exec("ip addr 2>/dev/null | grep UP | grep -v 'lo:' | head -1") ?: '');
        if ($firstUp === '' || !preg_match('/\d+:\s*(\w+):?/', $firstUp, $m)) {
            return null;
        }
        $iface = $m[1];
        $out = shell_exec("ip addr show dev " . escapeshellarg($iface) . " 2>/dev/null");
        if ($out && preg_match('/inet\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/\d{1,2}/', $out, $m)) {
            return $m[1];
        }
        return null;
    }
}
