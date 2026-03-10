<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\CustomClasses\Ami;
use Laravel\Sanctum\PersonalAccessToken;
use Tuupola\Ksuid;

if (!function_exists('pbx3_database_key_exists')) {
    function pbx3_database_key_exists($candidateKey) {
        return DB::table('master_xref')->where('pkey', '=', $candidateKey)->count();    
    }
}

if (!function_exists('move_request_to_model')) {
    /**
     * Updates a model ready for saving
     *
     * @param obj $request
     * Input
     *
     * @param obj $model
     * Target
     *
     * @param array $updateableColumns
     * Named columns to move 
     *
     * @return NULL
     *
     * */
    function move_request_to_model($request, $model, $updateableColumns) {
        // Iterate over updateable keys and pull from request via input() so JSON body
        // is included (e.g. PUT with application/json); all fields treated the same.
        foreach (array_keys($updateableColumns) as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                $model->$key = is_string($value) ? trim($value) : $value;
            }
        }
        return;
    }
}

if (!function_exists('get_location')) {
    function get_location() {
        $globals = get_globals();
        $location = $globals->NATDEFAULT;
        if ($globals->VCL) {
            $location = 'remote';
        } 
        return $location;       
    }
}

if (!function_exists('get_globals')) {
    function get_globals() {
        return DB::table('globals')->first();
    }
}

/**
 * Resolve cluster identifier (pkey, shortuid, or id) to cluster shortuid for storage.
 * Tenant-scoped tables must store cluster shortuid (not pkey) for RI and uniqueness,
 * since pkey is human-provided and can be duplicated across systems.
 *
 * @param string|null $identifier  Cluster pkey, shortuid, or id from client
 * @return string|null  Cluster shortuid, or null if not found
 */
if (!function_exists('cluster_identifier_to_shortuid')) {
    function cluster_identifier_to_shortuid($identifier) {
        if ($identifier === null || $identifier === '') {
            return null;
        }
        $row = DB::table('cluster')
            ->where('pkey', $identifier)
            ->orWhere('shortuid', $identifier)
            ->orWhere('id', $identifier)
            ->first(['shortuid']);
        return $row ? $row->shortuid : null;
    }
}

/**
 * Attach tenant_pkey (cluster display name) to each item in a collection that has a cluster property.
 * Used for PDF/CSV exports so views can show tenant pkey instead of cluster id/shortuid.
 *
 * @param \Illuminate\Support\Collection $collection
 * @return \Illuminate\Support\Collection
 */
if (!function_exists('attach_tenant_pkey_to_collection')) {
    function attach_tenant_pkey_to_collection($collection) {
        $map = [];
        try {
            $rows = DB::table('cluster')->get(['id', 'shortuid', 'pkey']);
            foreach ($rows as $row) {
                if (isset($row->id)) {
                    $map[(string) $row->id] = $row->pkey ?? $row->id;
                }
                if (isset($row->shortuid)) {
                    $map[(string) $row->shortuid] = $row->pkey ?? $row->shortuid;
                }
                if (isset($row->pkey)) {
                    $map[(string) $row->pkey] = $row->pkey;
                }
            }
        } catch (\Throwable $e) {
            // cluster table may not exist in some contexts
        }
        foreach ($collection as $item) {
            $c = $item->cluster ?? null;
            $item->tenant_pkey = ($c !== null && $c !== '') ? ($map[(string) $c] ?? $c) : $c;
        }
        return $collection;
    }
}

if (!function_exists('set_commit_dirty')) {
    /**
     * Mark instance as having uncommitted DB changes (Save was done; Commit not yet run).
     * Sets globals.mycommit = 'YES' so the Commit button shows red.
     */
    function set_commit_dirty() {
        try {
            DB::table('globals')->update(['mycommit' => 'YES']);
        } catch (\Throwable $e) {
            // instance schema may not be in use; ignore
        }
    }
}

if (!function_exists('pbx3_request_syscmd')) {
    /**
     * Send a command to the privileged syshelper daemon (port 7601).
     * Protocol: connect, read "Ready", send command+\n, read until <<EOT>>.
     * Use for any privileged operation (no sudo in API).
     *
     * @param string $command Command to run (no sudo; daemon runs privileged).
     * @return array{0: string|null, 1: string|null} [response body, or null; error message, or null]
     */
    function pbx3_request_syscmd(string $command): array
    {
        $host = env('PBX3_SYSCMD_HOST', '127.0.0.1');
        $port = (int) env('PBX3_SYSCMD_PORT', 7601);
        $timeout = (int) env('PBX3_SYSCMD_TIMEOUT', 5);

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return [null, "syshelper not reachable ({$host}:{$port}): {$errstr}"];
        }

        stream_set_timeout($fp, $timeout);
        $ack = @fgets($fp, 8192);
        if ($ack === false || trim($ack) !== 'Ready') {
            fclose($fp);
            return [null, 'syshelper did not send Ready'];
        }

        if (@fwrite($fp, $command . "\n") === false) {
            fclose($fp);
            return [null, 'failed to send command'];
        }

        $response = '';
        while (($line = @fgets($fp, 8192)) !== false) {
            if (strpos($line, '<<EOT>>') !== false) {
                break;
            }
            $response .= $line;
        }
        fclose($fp);

        return [trim($response), null];
    }
}

if (!function_exists('valid_ip_or_domain')) {
    /**
     * Checks host for valid IP, valid domain name (DNS A record), or hostname format.
     * Accepts hostnames that look valid (labels with alphanumeric/hyphen separated by dots)
     * when DNS lookup fails (e.g. private DNS, or host only resolvable from another network).
     *
     * @param string $host
     * @return bool
     */
    function valid_ip_or_domain($host) {
        if (empty($host) || !is_string($host)) {
            return false;
        }
        $host = trim($host);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (checkdnsrr($host, 'A')) {
            return true;
        }

        // Accept hostname format when DNS fails (e.g. sip.example.pbx, internal hosts)
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $host)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('create_new_backup')) {
    /**
     * checks host for valid IP or valid domain name
     *
     * @param none
     *
     * @return backup filename
     *
     * */
    function create_new_backup() {

        $backupSet = [
            '/opt/pbx3/db/sqlite.db',
            '/usr/share/asterisk/sounds',
            '/var/spool/asterisk/voicemail',
            '/etc/asterisk',
            '/etc/shorewall',
            '/tmp/pbx3.local.ldif'
        ];

        // Ensure backup directory exists (via syshelper for privileged operation)
        if (!file_exists('/opt/pbx3/bkup')) {
            [$response, $error] = pbx3_request_syscmd('/bin/mkdir -p /opt/pbx3/bkup');
            if ($error !== null) {
                Log::error("Failed to create backup directory: $error");
                throw new \Exception("Failed to create backup directory: $error");
            }
            [$response, $error] = pbx3_request_syscmd('/bin/chown www-data:www-data /opt/pbx3/bkup');
            if ($error !== null) {
                Log::warning("Failed to chown backup directory: $error");
            }
            [$response, $error] = pbx3_request_syscmd('/bin/chmod 755 /opt/pbx3/bkup');
            if ($error !== null) {
                Log::warning("Failed to chmod backup directory: $error");
            }
        }

        shell_exec('/usr/sbin/slapcat > /tmp/pbx3.local.ldif');
        $newBackupName = "pbx3bak." . time() . ".zip";
        $tmpBackupPath = "/tmp/$newBackupName";
        $finalBackupPath = "/opt/pbx3/bkup/$newBackupName";
       
        // Remove any existing temp backup file
        if (file_exists($tmpBackupPath)) {
            unlink($tmpBackupPath);
        }

        foreach($backupSet as $file) { 
            if(file_exists($file)) {
                Log::info("zipping " . $file); 
                shell_exec("/usr/bin/zip -r $tmpBackupPath $file");
            } 
            else {
                Log::info($file . " not found");
            }
        } 
        
        // Verify backup file was created before moving
        if (!file_exists($tmpBackupPath)) {
            Log::error("Backup file was not created at $tmpBackupPath");
            throw new \Exception("Failed to create backup file");
        }

        // Move file via syshelper (privileged operation)
        [$response, $error] = pbx3_request_syscmd("/bin/mv $tmpBackupPath $finalBackupPath");
        if ($error !== null) {
            Log::error("Failed to move backup file via syshelper: $error");
            throw new \Exception("Failed to move backup file to destination: $error");
        }
        
        // Verify file was moved successfully
        if (!file_exists($finalBackupPath)) {
            Log::error("Backup file was not moved to $finalBackupPath");
            throw new \Exception("Failed to move backup file to destination");
        }

        // Set ownership and permissions via syshelper (privileged operation)
        [$response, $error] = pbx3_request_syscmd("/bin/chown www-data:www-data $finalBackupPath");
        if ($error !== null) {
            Log::warning("Failed to chown backup file: $error");
        }
        [$response, $error] = pbx3_request_syscmd("/bin/chmod 664 $finalBackupPath");
        if ($error !== null) {
            Log::warning("Failed to chmod backup file: $error");
        }
        
        return $newBackupName;  

    }

}

if (!function_exists('create_new_snapshot')) {
    /**
     * checks host for valid IP or valid domain name
     *
     * @param none
     *
     * @return snap file name
     *
     * */
    function create_new_snapshot() {

        // Ensure snapshot directory exists (via syshelper for privileged operation)
        if (!file_exists('/opt/pbx3/snap')) {
            [$response, $error] = pbx3_request_syscmd('/bin/mkdir -p /opt/pbx3/snap');
            if ($error !== null) {
                Log::error("Failed to create snapshot directory: $error");
                throw new \Exception("Failed to create snapshot directory: $error");
            }
            [$response, $error] = pbx3_request_syscmd('/bin/chown www-data:www-data /opt/pbx3/snap');
            if ($error !== null) {
                Log::warning("Failed to chown snapshot directory: $error");
            }
            [$response, $error] = pbx3_request_syscmd('/bin/chmod 755 /opt/pbx3/snap');
            if ($error !== null) {
                Log::warning("Failed to chmod snapshot directory: $error");
            }
        }

        $newSnapshotName = "sqlite.db." . time();
        $snapshotPath = "/opt/pbx3/snap/$newSnapshotName";
        
        // Use syshelper for privileged snapshot creation
        [$response, $error] = pbx3_request_syscmd("/bin/cp /opt/pbx3/db/sqlite.db $snapshotPath");
        if ($error !== null) {
            Log::error("Failed to create snapshot via syshelper: $error");
            throw new \Exception("Failed to create snapshot: $error");
        }
        
        // Verify snapshot was created
        if (!file_exists($snapshotPath)) {
            Log::error("Snapshot file was not created at $snapshotPath");
            throw new \Exception("Failed to create snapshot file");
        }

        [$response, $error] = pbx3_request_syscmd("/bin/chown www-data:www-data $snapshotPath");
        if ($error !== null) {
            Log::warning("Failed to chown snapshot file: $error");
        }
        [$response, $error] = pbx3_request_syscmd("/bin/chmod 664 $snapshotPath");
        if ($error !== null) {
            Log::warning("Failed to chmod snapshot file: $error");
        }
        
        return $newSnapshotName;  

    }

}


if (!function_exists('restore_from_backup')) {

function restore_from_backup($request) {
    
/* 
 * Unzip the backup file
 */
    if (!file_exists("/opt/pbx3/bkup/" . $request->backup)) {
        Log::info("Requested restore set not found");
        return 404;
    }

/* 
 * start restore
 */

    $tempDname = "/tmp/bkup" . time();
    shell_exec("/bin/mkdir $tempDname");
    $unzipCmd = "/usr/bin/unzip /opt/pbx3/bkup/" . $request->backup . " -d $tempDname";
    shell_exec($unzipCmd);
    if (!file_exists($tempDname)) {
        Log::info("Restore unzip did not create a directory!");
        return 500;
    }
    
/*
 * now we can begin the restore
 */     
    if ( $request->restoredb === true) {
        // Check for sqlite.db (new) or pbx3.db (old backups) for backward compatibility
        $dbSource = null;
        if (file_exists($tempDname . '/opt/pbx3/db/sqlite.db')) {
            $dbSource = $tempDname . '/opt/pbx3/db/sqlite.db';
        } elseif (file_exists($tempDname . '/opt/pbx3/db/pbx3.db')) {
            $dbSource = $tempDname . '/opt/pbx3/db/pbx3.db';
        }
        if ($dbSource) {
            Log::info("Restoring the Database from $dbSource");
            shell_exec("/bin/cp -f $dbSource /opt/pbx3/db/sqlite.db");
            Log::info("Setting DB ownership");
            shell_exec("/bin/chown www-data:www-data /opt/pbx3/db/sqlite.db");
            Log::info("Running the reloader to sync versions");
            shell_exec("/bin/sh /opt/pbx3/scripts/srkV4reloader.sh");      
            Log::info("Database restore complete");
            Log::info("Database RESTORED");
        }
        else {
            Log::info("No Database in backup set - request ignored");
            Log::info("Database PRESERVED");
        }           
    }
    else {
        Log::info("Database PRESERVED");  
    }

    if ( $request->restoreasterisk === true ) {
        if (file_exists($tempDname . '/etc/asterisk')) {
            shell_exec("sudo /bin/rm -rf /etc/asterisk/*");
            shell_exec("/bin/cp -a  $tempDname/etc/asterisk/* /etc/asterisk");
            shell_exec("/bin/chown asterisk:asterisk /etc/asterisk/*");
            shell_exec("/bin/chmod 664 /etc/asterisk/*");
            Log::info("Asterisk files RESTORED");
        }
        else {
            Log::info("No Asterisk files in backup set; request ignored");
            Log::info("<p>Asterisk Files PRESERVED");
        }       
    }
    else {
        Log::info("Asterisk Files PRESERVED");    
    }   
                        
    if ( $request->restoreusergreets  === true) {
        if (glob($tempDname . '/usr/share/asterisk/sounds/usergreeting*')) {
            shell_exec("/bin/rm -rf /usr/share/asterisk/sounds/usergreeting*");
            shell_exec("/bin/cp -a  $tempDname/usr/share/asterisk/sounds/usergreeting* /usr/share/asterisk/sounds");
            shell_exec("/bin/chown asterisk:asterisk /usr/share/asterisk/sounds/usergreeting*");
            shell_exec("/bin/chmod 664 /usr/share/asterisk/sounds/usergreeting*");

            Log::info("Greeting files RESTORED");
        }
        else {
            Log::info("No greeting files in backup set; request ignored");
            Log::info("Greeting files PRESERVED");
        }
    }
    else {
        Log::info("Greeting files PRESERVED");    
    }
        
    if ( $request->restorevmail === true) {
        if (file_exists($tempDname . '/var/spool/asterisk/voicemail/default')) {
            shell_exec("/bin/rm -rf /var/spool/asterisk/voicemail/default");
            shell_exec("/bin/cp -a $tempDname/var/spool/asterisk/voicemail/default /var/spool/asterisk/voicemail");
            shell_exec("/bin/chown -R asterisk:asterisk /var/spool/asterisk/voicemail/default");
            shell_exec("/bin/chmod 664 /var/spool/asterisk/voicemail/default");
            Log::info("Voicemail files RESTORED");
        }
        else {
            Log::info("No voicemail files in backup set; request ignored");
            Log::info("Voicemail files PRESERVED");
        }
    }
    else {
        Log::info("Voicemail files PRESERVED");   
    }
    
    if ( $request->restoreldap === true) {
        if (file_exists($tempDname . '/tmp/pbx3.local.ldif')) {
            shell_exec("sudo /etc/init.d/slapd stop");
            shell_exec("sudo /bin/rm -rf /var/lib/ldap/*");
            shell_exec("sudo /usr/sbin/slapadd -l " . $tempDname . "/tmp/pbx3.local.ldif");
            shell_exec("sudo /bin/chown openldap:openldap /var/lib/ldap/*");
            shell_exec("sudo /etc/init.d/slapd start");  
            Log::info("LDAP Directory RESTORED");
        }
        else {
            Log::info("No LDAP Directory in backup set; request ignored");
            Log::info("LDAP Directory PRESERVED");
        }
    }
    else {
        Log::info("LDAP Directory PRESERVED");    
    }   
    
    shell_exec("/bin/rm -rf $tempDname");
    Log::info("Temporary work files deleted");
    Log::info("Requesting Asterisk reload");
    shell_exec("/bin/sh /opt/pbx3/scripts/srkreload");
    Log::info("System Regen complete");

    return 200; 
    } 
}

if (!function_exists('get_ami_handle')) {

/**
 * get_ami_handle get a handle
 * @return object ref AMI
 */
    function get_ami_handle() {

        if  (!`/bin/ps -e | /bin/grep asterisk | /bin/grep -v grep`) {
            Response::make(['message' => 'PBX not running'],503)->send();
        }

        $params = array('server' => '127.0.0.1', 'port' => '5038');
        $amiHandle = new Ami($params);
        $amiconrets = $amiHandle->connect();
        if ( !$amiconrets ) {            
            Response::make(['message' => 'Service Unavailable - Could not connect to the PBX'],599)->send();
        }
        else {
            $amiHandle->login('pbx3','bgth7rf!');
        } 
        return $amiHandle;  
    } 
}

if (!function_exists('pjsip_endpoint_live')) {
    /**
     * Get live PJSIP endpoint data (IP and RTT) from Asterisk AMI.
     * Uses existing AMI handle; does not logout.
     *
     * @param \App\CustomClasses\Ami $amiHandle Connected AMI handle
     * @param string $pkey Extension/endpoint pkey (e.g. 101)
     * @return array{ip: string, latency: string} ip address and latency display (e.g. "OK (5 ms)" or "Unknown")
     */
    function pjsip_endpoint_live($amiHandle, $pkey) {
        $out = ['ip' => null, 'latency' => null];
        try {
            $response = $amiHandle->amiQueryUntilComplete("Action: PJSIPShowEndpoint\r\nEndpoint: " . $pkey);
        } catch (\Throwable $e) {
            Log::warning('PJSIPShowEndpoint failed', ['pkey' => $pkey, 'error' => $e->getMessage()]);
            $out['ip'] = 'Unknown';
            $out['latency'] = 'Unknown';
            return $out;
        }
        // Parse multi-event response - collect ALL key-value pairs from all events (like old system)
        // Old system ignores Event, ListItems, EventList, ObjectType, ObjectName and collects everything else
        $lines = explode("\r\n", (string) $response);
        $kv = [];
        foreach ($lines as $line) {
            // Ignore lines that aren't key-value pairs
            if (!preg_match('/:/', $line)) {
                continue;
            }
            
            // Parse the key-value pair
            $couplet = explode(': ', $line, 2);
            if (count($couplet) !== 2) {
                continue;
            }
            
            $key = trim($couplet[0]);
            $value = trim($couplet[1]);
            
            // Ignore event metadata fields (like old system)
            if ($key === 'Event' || $key === 'ListItems' || $key === 'EventList' || 
                $key === 'ObjectType' || $key === 'ObjectName' || $key === 'Response' || 
                $key === 'Message') {
                continue;
            }
            
            // Collect all other fields into flat array
            $kv[$key] = $value;
        }
        
        // Extract IP: URI first (sip:user@ip:port), then Match field
        if (!empty($kv['URI'])) {
            if (preg_match('/^sip:.*@([^:]+)(?::|;|$)/', $kv['URI'], $m)) {
                $out['ip'] = trim($m[1]);
            }
        }
        if ($out['ip'] === null && !empty($kv['Match'])) {
            $matchParts = explode('/', $kv['Match']);
            if (!empty($matchParts[0])) {
                $out['ip'] = trim($matchParts[0]);
            }
        }
        if ($out['ip'] === null) {
            $out['ip'] = 'Unknown';
        }
        
        // Extract latency from RoundtripUsec
        if (!empty($kv['RoundtripUsec']) && is_numeric($kv['RoundtripUsec'])) {
            $ms = (int) round((float) $kv['RoundtripUsec'] / 1000);
            $out['latency'] = 'OK (' . $ms . ' ms)';
        } else {
            $out['latency'] = 'Unknown';
        }
        return $out;
    }
} 

if (!function_exists('pbx_is_running')) {
    function pbx_is_running() {

        if  (`/bin/ps -e | /bin/grep asterisk | /bin/grep -v grep`) {
            return true;
        }

        return false; 
    }
}

if (!function_exists('idpwgen_run')) {
    /**
     * Run idpwgen binary and return validated output.
     * @param string $path    path to idpwgen binary
     * @param int    $length
     * @param string $charset
     * @return string
     * @throws \RuntimeException on failure
     */
    function idpwgen_run($path, $length, $charset) {
        if (!is_executable($path)) {
            throw new \RuntimeException('idpwgen not executable: ' . $path);
        }
        $cmd = $path . ' -length ' . (int) $length . ' -charset ' . escapeshellarg($charset);
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('idpwgen proc_open failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            Log::warning('idpwgen failed', ['exit_code' => $code, 'stderr' => trim($stderr)]);
            throw new \RuntimeException('idpwgen failed (exit ' . $code . ')');
        }
        $out = trim($stdout);
        if (strlen($out) !== $length) {
            throw new \RuntimeException('idpwgen wrong length: got ' . strlen($out) . ', expected ' . $length);
        }
        $charsetArr = array_flip(str_split($charset));
        for ($i = 0; $i < strlen($out); $i++) {
            if (!isset($charsetArr[$out[$i]])) {
                throw new \RuntimeException('idpwgen invalid character in output');
            }
        }
        return $out;
    }
}

if (!function_exists('ret_password')) {
    /**
     * Generate a phone password using idpwgen (12 chars, digits + mixed case, no ambiguous).
     */
    function ret_password($length = 12) {
        $path = env('IDPWGEN_PATH', '/opt/pbx3/golang/idpwgen');
        $possible = '2346789bcdfghjkmnpqrtvwxyzBCDFGHJKLMNPQRTVWXYZ';
        $maxlength = strlen($possible);
        if ($length > $maxlength) {
            $length = $maxlength;
        }
        return idpwgen_run($path, $length, $possible);
    }
}

if (!function_exists('generate_ksuid')) {
    /** Generate a KSUID string (base62) using tuupola/ksuid. */
    function generate_ksuid() {
        $ksuid = new Ksuid();
        return $ksuid->string();
    }
}

if (!function_exists('generate_shortuid')) {
    /**
     * Generate a shortuid using idpwgen (same as pbx3 HelperClass::generate()).
     * Default: 6 chars, charset 0123456789bcdfghjkmnpqrstvwxyz (no vowels/similar chars).
     *
     * @param int    $length  default 6
     * @param string $charset default shortuid charset
     * @return string
     */
    function generate_shortuid($length = 6, $charset = '')
    {
        $path = env('IDPWGEN_PATH', '/opt/pbx3/golang/idpwgen');
        $charset = $charset ?: '0123456789bcdfghjkmnpqrstvwxyz';
        return idpwgen_run($path, $length, $charset);
    }
}