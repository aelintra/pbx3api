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

        $system = [
            'distro' => $this->safeShell("lsb_release -ds 2>/dev/null"),
            'pbx_release' => null,
            'app_release' => null,
            'endpoints_licenced' => null,
            'endpoints_defined' => null,
            'serial' => null,
        ];

        if (`/bin/ps -e 2>/dev/null | /bin/grep asterisk | /bin/grep -v grep`) {
            $ver = trim(`$asterisk -rx 'core show version' 2>/dev/null`);
            $system['pbx_release'] = preg_match('/^Asterisk\s+([^\s~]+)/', $ver, $m) ? $m[1] : (strlen($ver) ? $ver : null);
        }

        $system['app_release'] = trim(`dpkg-query -W -f '\${Version}' pbx3 2>/dev/null`) ?: null;

        try {
            $extcount = DB::table('ipphone')->count();
            $system['endpoints_defined'] = $extcount;
        } catch (\Throwable $e) {
            $system['endpoints_defined'] = null;
        }

        try {
            $row = DB::table('globals')->where('pkey', 'global')->first();
            if ($row && isset($row->EXTLIM)) {
                $system['endpoints_licenced'] = $row->EXTLIM;
            }
            if ($row && isset($row->extlim)) {
                $system['endpoints_licenced'] = $row->extlim;
            }
            if ($system['endpoints_licenced'] === null) {
                $c = DB::table('cluster')->where('pkey', 'default')->first();
                if ($c && isset($c->ext_lim)) {
                    $system['endpoints_licenced'] = $c->ext_lim;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $network = [
            'hostname' => gethostname() ?: null,
            'mac' => $this->safeShell("ip route show default 2>/dev/null | head -1 | awk '{for(i=1;i<=NF;i++)if(\$i==\"dev\"){print \$(i+1);exit}}' | xargs -I{} ip link show {} 2>/dev/null | awk '/ether/{print \$2}'"),
            'public_ip' => $this->safeShell("curl -s -m 2 ifconfig.me 2>/dev/null") ?: null,
            'dhcp_ip' => null,
            'static_ip' => null,
        ];

        try {
            $g = DB::table('globals')->where('pkey', 'global')->first();
            if ($g && !empty($g->staticipv4)) {
                $network['static_ip'] = $g->staticipv4;
            }
            if ($g && !empty($g->localip)) {
                $network['static_ip'] = $network['static_ip'] ?? $g->localip;
            }
        } catch (\Throwable $e) {
            // ignore
        }

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
}
