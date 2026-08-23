<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Fleet\FleetPostureService;
use App\Support\ExtLenPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class TenantController extends Controller
{

	// cluster table (full_schema.sql). Exclude id, pkey, shortuid, z_*. Display id/pkey in UI.
	private $updateableColumns = [
			'abstimeout' => 'integer',
			'active' => 'in:YES,NO',
			'allow_hash_xfer' => 'in:enabled,disabled',
			'blind_busy' => 'string|nullable',
			'bounce_alert' => 'string|nullable',
			'callrecord_1' => 'in:None,In,Out,Both',
			'camp_on_q_onoff' => 'string|nullable',
			'camp_on_q_opt' => 'string|nullable',
			'cfwdextern_rule' => 'in:YES,NO',
			'cfwd_progress' => 'in:enabled,disabled',
			'cfwd_answer' => 'in:enabled,disabled',
			'clusterclid' => 'nullable|string|regex:/^\d*$/',
			'chanmax' => 'integer',
			'cname' => 'string|nullable',
			'countrycode' => 'integer',
			'description' => 'string',
			'devicerec' => 'string|nullable',
			'dynamicfeatures' => 'string|nullable',
			'emailalert' => 'string|nullable',
			'emergency' => 'string|nullable',
			'ext_lim' => 'integer|nullable',
			'ext_len' => 'nullable|integer|min:'.ExtLenPolicy::MIN.'|max:'.ExtLenPolicy::MAX,
			'fqdninspect' => 'boolean',
			'int_ring_delay' => 'integer',
			'ivr_key_wait' => 'integer',
			'ivr_digit_wait' => 'integer',
			'language' => 'string|nullable',
			'ldapanonbind' => 'nullable|in:YES,NO',
			'ldapbase' => 'string|nullable',
			'ldaphost' => 'string|nullable',
			'ldapou' => 'string|nullable',
			'ldapuser' => 'string|nullable',
			'ldappass' => 'nullable|string',
			'ldaptls' => 'in:on,off',
			'localarea' => 'nullable|string|regex:/^\d*$/',
			'localdplan' => ['regex:/^_X+$/', 'nullable'],
			'lterm' => 'integer|nullable',
			'leasedhdtime' => 'integer|nullable',
			'masteroclo' => 'in:AUTO,CLOSED',
			'maxin' => 'integer',
			'maxout' => 'integer|nullable',
			'mixmonitor' => 'string|nullable',
			'monitor_out' => 'string|nullable',
			'monitor_stage' => 'string|nullable',
			'operator' => 'integer',
			'play_beep' => 'integer|nullable',
			'play_busy' => 'integer|nullable',
			'play_congested' => 'integer|nullable',
			'play_transfer' => 'integer|nullable',
			'rec_age' => 'integer',
			'rec_final_dest' => 'string|nullable',
			'rec_file_dlim' => 'string|nullable',
			'rec_grace' => 'integer',
			'rec_limit' => 'integer|nullable',
			'rec_mount' => 'string|nullable',
			'recmaxage' => 'integer|nullable',
			'recmaxsize' => 'integer|nullable',
			'ringdelay' => 'integer',
			'spy_pass' => 'nullable|string|max:64',
			'sysop' => 'integer|nullable',
			'syspass' => 'nullable|string|max:64',
			'usemohcustom' => 'in:YES,NO',
			'VDELAY' => 'integer|nullable',
			'vmail_age' => 'integer',
			'voice_instr' => 'integer|nullable',
			'voip_max' => 'integer',
			'park_overlay' => 'nullable|string|max:16384',
	];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

    //
/**
 * Return Tenant Index in pkey order asc
 * 
 * @return Tenants
 */
    public function index () {

    	return Tenant::orderBy('pkey','asc')->get();
    }

    /** Export tenants list as PDF. Same dataset as index. */
    public function exportPdf()
    {
        $tenants = Tenant::orderBy('pkey', 'asc')->get();
        return Pdf::loadView('exports.tenants-pdf', ['tenants' => $tenants])
            ->setPaper('a4', 'landscape')
            ->download('tenants.pdf');
    }

/**
 * Return named Tenant instance
 * 
 * @param  Tenant
 * @return Tenant object
 */
    public function show (Tenant $tenant) {
    	return $tenant;
    }

 /**
 * Save new tenant instance
 * 
 * @param  Tenant
 */
    public function save (Request $request) {

        // Fleet-first: Sanctum Create is locked on fleet nodes; Gatekeeper uses POST /fleet/tenants.
        if ($request->user() !== null && app(FleetPostureService::class)->isFleetNode()) {
            return response()->json([
                'message' => 'Fleet mode: create tenants via Fleet → Tenants (not on-node Create)',
            ], 403);
        }

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => 'required|string',
            'description' => 'string|required',
        ]);

    	$validator = Validator::make($request->all(), $createRules); 

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        if (Tenant::where('pkey','=',$request->pkey)->count()) {
           return Response::json(['Error' => 'Key already exists'],409); 
        }

    	$tenant = new Tenant;
		$tenant->id = generate_ksuid();
		$tenant->shortuid = generate_shortuid();

// Move post variables to the model 
    	move_request_to_model($request, $tenant, $createRules);
        if (! $request->filled('ext_len')) {
            $tenant->ext_len = ExtLenPolicy::DEFAULT;
        } else {
            $tenant->ext_len = ExtLenPolicy::normalize($request->input('ext_len'));
        }

        $instanceDomain = '';
        try {
            $grow = DB::table('globals')->first(['domain']);
            if ($grow && isset($grow->domain)) {
                $instanceDomain = trim((string) $grow->domain);
            }
        } catch (\Throwable $e) {
            $instanceDomain = '';
        }
        // FQDN always {shortuid}.{apex} (FLEET_NAMING_LOCK) — ignore client vanity fqdn.
        if ($instanceDomain !== '') {
            $tenant->domain = $instanceDomain;
            $tenant->fqdn = $tenant->shortuid.'.'.$instanceDomain;
        } else {
            if ($request->input('domain') !== null && trim((string) $request->input('domain')) !== '') {
                $tenant->domain = trim((string) $request->input('domain'));
            }
            $dom = trim((string) ($tenant->domain ?? ''));
            if ($dom !== '') {
                $tenant->fqdn = $tenant->shortuid.'.'.$dom;
            }
        }

// store the new model
    	try {

    		$tenant->save();
            pbx3_update_fqdn_inline_optional();
            app(\App\Services\Tenant\SeedOutboundRouteOnTenantCreate::class)->seed($tenant);
            $cosSeed = app(\App\Services\Tenant\SeedCosHighRiskOnTenantCreate::class);
            if ($cosSeed->seedEnabledByConfig()) {
                $cosSeed->seed($tenant);
            }

        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

    	return $tenant;
    }

 /**
 * update tenant instance
 * 
 * @param  Tenant
 * @return tenant object
 */
    public function update(Request $request, Tenant $tenant) {


// Validate         
    	$validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request, $tenant) {
            if (! $request->has('ext_len')) {
                return;
            }
            $newLen = ExtLenPolicy::normalize($request->input('ext_len'));
            $oldLen = ExtLenPolicy::normalize($tenant->ext_len ?? null);
            if ($newLen === $oldLen) {
                return;
            }
            $aliases = cluster_identifier_aliases($tenant->shortuid ?? $tenant->pkey);
            if ($aliases === []) {
                $aliases = array_filter([(string) ($tenant->shortuid ?? ''), (string) ($tenant->pkey ?? '')]);
            }
            try {
                $pkeys = DB::table('ipphone')->whereIn('cluster', $aliases)->pluck('pkey');
                foreach ($pkeys as $pk) {
                    if (! ExtLenPolicy::isValidExtensionPkey($pk, $newLen)) {
                        $validator->errors()->add(
                            'ext_len',
                            "Cannot set extension length to {$newLen}: existing extension {$pk} is not exactly {$newLen} digits."
                        );
                        return;
                    }
                }
            } catch (\Throwable $e) {
                // table may be missing in tests
            }
            try {
                $routes = DB::table('route')->whereIn('cluster', $aliases)->get(['pkey', 'dialplan']);
                foreach ($routes as $r) {
                    $dp = trim((string) ($r->dialplan ?? ''));
                    if ($dp === '') {
                        continue;
                    }
                    $err = ExtLenPolicy::dialplanError($dp, $newLen);
                    if ($err !== null) {
                        $validator->errors()->add(
                            'ext_len',
                            "Cannot set extension length to {$newLen}: OutRoute {$r->pkey} dialplan is incompatible ({$err})"
                        );
                        return;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        });

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}		

// Move post variables to the model  

		move_request_to_model($request,$tenant,$this->updateableColumns);
        if ($request->has('ext_len')) {
            $tenant->ext_len = ExtLenPolicy::normalize($request->input('ext_len'));
        }
		if ($request->has('park_overlay')) {
			$ov = $request->input('park_overlay');
			$tenant->park_overlay = ($ov === null || (is_string($ov) && trim($ov) === '')) ? null : (is_string($ov) ? trim($ov) : $ov);
		}

// store the model if it has changed
    	try {
    		if ($tenant->isDirty()) {
    			$tenant->save();
                if ($tenant->wasChanged()) {
                    pbx3_update_fqdn_inline_optional();
                }
    		}

        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

		return response()->json($tenant, 200);
    }   

/**
 * T1 — per-table wipe blast-radius counts before solo Sanctum Delete confirm.
 */
    public function wipePreflight(Tenant $tenant, \App\Services\Tenant\TenantMobilityService $mobility)
    {
        if ($tenant->pkey == 'default') {
            return Response::json(['message' => 'Cannot delete default tenant'], 409);
        }

        // Fleet nodes wipe via Fleet Delete job (counts come from GET /fleet/tenants/.../wipe-preflight).
        if (request()->user() !== null && app(FleetPostureService::class)->isFleetNode()) {
            return response()->json([
                'message' => 'Fleet mode: use Fleet Delete preflight (not on-node wipe-preflight)',
            ], 403);
        }

        try {
            $counts = $mobility->countTenantData($tenant);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'wipe' => $counts,
        ]);
    }

/**
 * Delete tenant instance
 * @param  Tenant
 * @return [type]
 */
    public function delete(Tenant $tenant) {

// Don't allow deletion of default tenant

        if ($tenant->pkey == 'default') {
           return Response::json(['Error - Cannot delete default tenant!'],409); 
        }

        // Fleet-first: Sanctum Delete locked on fleet nodes (Fleet Delete orchestrator is follow-on).
        if (request()->user() !== null && app(FleetPostureService::class)->isFleetNode()) {
            return response()->json([
                'message' => 'Fleet mode: delete tenants via Fleet (not on-node Delete)',
            ], 403);
        }

        $id = $tenant->id;
        $shortuid = (string) $tenant->shortuid;
        pbx3_delete_park_asterisk_instances($shortuid);
        app(\App\Services\Tenant\TenantMobilityService::class)->destroyTenantData($tenant);
        app(\App\Services\Tenant\PortableUserMobility::class)->removeOrStripForTenant($shortuid);
        pbx3_update_fqdn_inline_optional();

        return response()->json(['tenant ' .$id .' deleted'],200);
    }

    private const MOH_ROOT = '/usr/share/asterisk';

    /** Absolute MOH directory for this tenant (CAGI / GenAst use shortuid). */
    private function mohDir(Tenant $tenant): string
    {
        $su = trim((string) $tenant->shortuid);
        if ($su === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $su)) {
            throw new \InvalidArgumentException('Tenant shortuid missing or invalid.');
        }

        return self::MOH_ROOT.'/moh-'.$su;
    }

    private function ensureMohDir(Tenant $tenant): string
    {
        $fullPath = $this->mohDir($tenant);
        [$out, $err] = pbx3_request_syscmd('/bin/mkdir -p '.escapeshellarg($fullPath));
        if ($err !== null) {
            throw new \RuntimeException('Unable to create MOH directory: '.$err);
        }
        [$out, $err] = pbx3_request_syscmd('/bin/chown www-data:www-data '.escapeshellarg($fullPath));
        if ($err !== null) {
            throw new \RuntimeException('Unable to set MOH directory ownership: '.$err);
        }
        [$out, $err] = pbx3_request_syscmd('/bin/chmod 755 '.escapeshellarg($fullPath));
        if ($err !== null) {
            throw new \RuntimeException('Unable to set MOH directory permissions: '.$err);
        }

        return $fullPath;
    }

    /** Sanitize upload basename (SARK-style); returns null if unusable. */
    private function sanitizeMohFilename(string $original): ?string
    {
        $base = basename(str_replace('\\', '/', $original));
        if (! preg_match('/^(.+)\.([A-Za-z0-9]+)$/', $base, $m)) {
            return null;
        }
        $stem = preg_replace('/[^A-Za-z0-9 ]/', '', $m[1]);
        $stem = preg_replace('/[^A-Za-z0-9]/', '_', (string) $stem);
        $stem = preg_replace('/_+/', '_', (string) $stem);
        $stem = trim((string) $stem, '_');
        $ext = strtolower($m[2]);
        if ($stem === '' || ! in_array($ext, ['wav', 'mp3', 'gsm'], true)) {
            return null;
        }

        return $stem.'.'.$ext;
    }

    /** List custom MOH files for this tenant. */
    public function listMoh(Tenant $tenant)
    {
        $dir = $this->mohDir($tenant);
        $files = [];
        if (is_dir($dir) && ($handle = opendir($dir))) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir.'/'.$entry;
                if (! is_file($path)) {
                    continue;
                }
                $files[] = [
                    'name' => $entry,
                    'size' => filesize($path) ?: 0,
                ];
            }
            closedir($handle);
        }
        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return response()->json([
            'directory' => 'moh-'.$tenant->shortuid,
            'usemohcustom' => $tenant->usemohcustom === 'YES' ? 'YES' : 'NO',
            'files' => $files,
        ]);
    }

    /** Upload a custom MOH file (wav preferred; sox → 8 kHz mono). */
    public function uploadMoh(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:51200',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $upload = $request->file('file');
        $safeName = $this->sanitizeMohFilename($upload->getClientOriginalName() ?: 'moh.wav');
        if ($safeName === null) {
            return response()->json(['file' => ['File name must be wav, mp3, or gsm after sanitizing.']], 422);
        }

        try {
            $dir = $this->ensureMohDir($tenant);
        } catch (\Throwable $e) {
            return response()->json(['Error' => $e->getMessage()], 409);
        }

        $target = $dir.'/'.$safeName;
        $tmp = $upload->getRealPath();
        if (! $tmp || ! is_readable($tmp)) {
            return response()->json(['file' => ['Upload temporary file missing.']], 422);
        }

        $ext = pathinfo($safeName, PATHINFO_EXTENSION);
        if ($ext === 'wav') {
            [$out, $err] = pbx3_request_syscmd(
                '/usr/bin/sox '.escapeshellarg($tmp).' -r 8000 -c 1 -e signed '.escapeshellarg($target).' -q'
            );
            if ($err !== null || ! file_exists($target)) {
                // Fall back to raw copy if sox missing / fails
                [$out, $err] = pbx3_request_syscmd('/bin/cp '.escapeshellarg($tmp).' '.escapeshellarg($target));
                if ($err !== null || ! file_exists($target)) {
                    return response()->json(['Error' => 'Failed to store MOH wav: '.($err ?? 'unknown')], 409);
                }
            }
        } else {
            [$out, $err] = pbx3_request_syscmd('/bin/cp '.escapeshellarg($tmp).' '.escapeshellarg($target));
            if ($err !== null || ! file_exists($target)) {
                return response()->json(['Error' => 'Failed to store MOH file: '.($err ?? 'unknown')], 409);
            }
        }

        pbx3_request_syscmd('/bin/chmod +r '.escapeshellarg($target));
        pbx3_request_syscmd('/bin/chown asterisk:asterisk '.escapeshellarg($target));

        return response()->json(['message' => "File {$safeName} uploaded", 'name' => $safeName], 200);
    }

    /** Stream/download one MOH file for in-browser play. */
    public function downloadMoh(Tenant $tenant, string $filename)
    {
        $safe = basename(str_replace('\\', '/', $filename));
        if ($safe === '' || $safe !== $filename || str_contains($safe, '..')) {
            return response()->json(['Error' => 'Invalid filename'], 422);
        }
        $path = $this->mohDir($tenant).'/'.$safe;
        if (! is_file($path)) {
            return response()->json(['Error' => 'File not found'], 404);
        }

        return response()->file($path);
    }

    /** Delete one custom MOH file. */
    public function deleteMoh(Tenant $tenant, string $filename)
    {
        $safe = basename(str_replace('\\', '/', $filename));
        if ($safe === '' || $safe !== $filename || str_contains($safe, '..')) {
            return response()->json(['Error' => 'Invalid filename'], 422);
        }
        $path = $this->mohDir($tenant).'/'.$safe;
        if (! is_file($path)) {
            return response()->json(['Error' => 'File not found'], 404);
        }
        [$out, $err] = pbx3_request_syscmd('/bin/rm -f '.escapeshellarg($path));
        if ($err !== null && is_file($path)) {
            return response()->json(['Error' => 'Failed to delete: '.$err], 409);
        }

        return response()->json(['message' => "Deleted {$safe}"], 200);
    }
}
