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
        // Use all() so JSON request body is read (post() is empty for application/json)
        foreach ($request->all() as $key => $value) {
            if (array_key_exists($key, $updateableColumns)) {
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

if (!function_exists('valid_ip_or_domain')) {
    /**
     * checks host for valid IP or valid domain name
     *
     * @param host reference
     *
     * @return boolean
     *
     * */
    function valid_ip_or_domain($host) {

        if (filter_var($host, FILTER_VALIDATE_IP)) {
        	return true;
        }

        if  (checkdnsrr($host, "A")   ) {

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

        shell_exec('/usr/sbin/slapcat > /tmp/pbx3.local.ldif');
        $newBackupName = "pbx3bak." . time() . ".zip";
       
        foreach($backupSet as $file) { 
            if(file_exists($file)) {
                Log::info("zipping " . $file); 
                shell_exec("/usr/bin/zip -r /tmp/$newBackupName $file");
            } 
            else {
                Log::info($file . " not found");
            }
        } 
        shell_exec("/bin/mv /tmp/$newBackupName /opt/pbx3/bkup/");
        shell_exec("/bin/chown www-data:www-data /opt/pbx3/bkup/$newBackupName ");
        shell_exec("/bin/chmod 664 /opt/pbx3/bkup/$newBackupName ");
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

        $newSnapshotName = "pbx3.db." . time();
        shell_exec("/bin/cp /opt/pbx3/db/pbx3.db /opt/pbx3/snap/$newSnapshotName");
        shell_exec("/bin/chown www-data:www-data /opt/pbx3/snap/$newSnapshotName");
        shell_exec("/bin/chmod 664 /opt/pbx3/snap/$newSnapshotName");
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
        if (file_exists($tempDname . '/opt/pbx3/db/pbx3.db')) {
            Log::info("Restoring the Database from $tempDname/opt/pbx3/db/pbx3.db");
            shell_exec("/bin/cp -f $tempDname/opt/pbx3/db/pbx3.db  /opt/pbx3/db/pbx3.db");
            Log::info("Setting DB ownership");
            shell_exec("/bin/chown www-data:www-data  /opt/pbx3/db/pbx3.db");
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
            $response = $amiHandle->amiQueryUntilBlankLine("Action: PJSIPShowEndpoint\r\nEndpoint: " . $pkey);
        } catch (\Throwable $e) {
            Log::warning('PJSIPShowEndpoint failed', ['pkey' => $pkey, 'error' => $e->getMessage()]);
            $out['ip'] = 'Unknown';
            $out['latency'] = 'Unknown';
            return $out;
        }
        $lines = explode("\r\n", (string) $response);
        $kv = [];
        foreach ($lines as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', trim($line), $m)) {
                $kv[$m[1]] = $m[2];
            }
        }
        // Debug logging - remove after fixing
        Log::info('PJSIPShowEndpoint response', [
            'pkey' => $pkey,
            'raw_response' => $response,
            'parsed_kv' => $kv,
            'has_Contact' => isset($kv['Contact']),
            'has_URI' => isset($kv['URI']),
            'has_Match' => isset($kv['Match']),
            'has_RoundtripUsec' => isset($kv['RoundtripUsec']),
        ]);
        if (!empty($kv['Contact'])) {
            // Handle formats like "sip:user@ip:port" or "prefix/sip:user@ip:port"
            if (preg_match('/sip:[^@]+@([^:;]+)(?::|;|$)/', $kv['Contact'], $m)) {
                $out['ip'] = trim($m[1]);
            }
        }
        if ($out['ip'] === null && !empty($kv['URI'])) {
            // Handle formats like "sip:user@ip:port" or "prefix/sip:user@ip:port"
            if (preg_match('/sip:[^@]+@([^:;]+)(?::|;|$)/', $kv['URI'], $m)) {
                $out['ip'] = trim($m[1]);
            }
        }
        if ($out['ip'] === null && !empty($kv['Match'])) {
            $parts = explode('/', $kv['Match']);
            if (!empty($parts[0])) {
                $out['ip'] = $parts[0];
            }
        }
        if ($out['ip'] === null) {
            $out['ip'] = 'Unknown';
        }
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

if (!function_exists('ret_password')) {
    function ret_password ($length = 12) {
    /*
     * generate a phone password
     */ 
        $password = "";
        $possible = "2346789bcdfghjkmnpqrtvwxyzBCDFGHJKLMNPQRTVWXYZ";
        $maxlength = strlen($possible);
        if ($length > $maxlength) {
          $length = $maxlength;
        }
        $i = 0; 
        while ($i < $length) { 
          $char = substr($possible, mt_rand(0, $maxlength-1), 1);       
          // have we already used this character in $password?
          if (!strstr($password, $char)) { 
            // no, so it's OK to add it onto the end of whatever we've already got...
            $password .= $char;
            // ... and increase the counter by one
            $i++;
          }
    
        }
        return $password;
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
     * @param int    $length
     * @param string $charset  Default: no vowels/similar chars (0/O, 1/I/l), low chance of rude words
     *
     * @return string
     */
    function generate_shortuid($length = 8, $charset = '')
    {
        $charset = $charset ?: '0123456789bcdfghjkmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ';
        $charset_size = strlen($charset);
        $uid = '';
        while ($length-- > 0) {
            $uid .= $charset[random_int(0, $charset_size - 1)];
        }

        return $uid;
    }
}