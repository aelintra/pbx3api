<?php

namespace App\Http\Controllers;

use App\Models\InboundRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class InboundRouteController extends Controller
{
    /** Asterisk dialplan extension format: literal digits, pattern _[XZN.!]+, or special s|i|t */
    private const PKEY_EXTENSION_REGEX = '/^(\d+|_[XZN.!]+|[sit])$/';

    // inroutes table (full_schema.sql). Exclude id, pkey, shortuid, z_*. 'carrier' is request-only → technology.
    private $updateableColumns = [
		'active' => 'in:YES,NO',
		'alertinfo' => 'string|nullable',
		'callback' => 'string|nullable',
		'callerid' => 'string|nullable',
		'callprogress' => 'in:YES,NO',
		'closeroute' => 'string|nullable',
		'cluster' => 'exists:cluster,pkey',
		'cname' => 'string|nullable',
		'description' => 'string|nullable',
		'devicerec' => 'string|nullable',
		'disa' => 'in:DISA,CALLBACK|nullable',
		'disapass' => 'string|nullable',
		'host' => 'string|nullable',
		'iaxreg' => 'string|nullable',
		'inprefix' => 'string|nullable',
		'match' => 'string|nullable',
		'moh' => 'in:YES,NO',
		'openroute' => 'string|nullable',
		'password' => 'string|nullable',
		'peername' => 'string|nullable',
		'pjsipreg' => 'string|nullable',
		'register' => 'string|nullable',
		'swoclip' => 'in:YES,NO',
		'tag' => 'string|nullable',
		'trunkname' => 'string|nullable',
		'username' => 'string|nullable',
    ];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

/**
 * Return InboundRoute index in pkey order asc.
 * Instance schema uses inroutes table (DDI/CLID); trunks are in trunks table.
 *
 * @return \Illuminate\Support\Collection
 */
    public function index () {

    	return InboundRoute::orderBy('pkey','asc')->get();
    }

/**
 * Return named extension model instance
 * 
 * @param  Extension
 * @return extension object
 */
    public function show (InboundRoute $inboundroute) {

    	return response()->json($inboundroute, 200);
    }

/**
 * Create a new Did instance
 * 
 * @param  Request
 * @return New Did
 */
    public function save(Request $request) {

        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

// validate (carrier is request-only, stored as technology in DB; pkey must be valid Asterisk extension)
        $rules = array_merge($this->updateableColumns, [
            'pkey' => ['required', 'regex:' . self::PKEY_EXTENSION_REGEX],
            'carrier' => 'required|in:DiD,CLID',
            'cluster' => 'required|exists:cluster,pkey',
            'trunkname' => 'nullable|alpha_num',
        ]);

        $inboundroute = new InboundRoute;
        $inboundroute->openroute = 'None';
        $inboundroute->closeroute = 'None';

        $messages = [
            'pkey.regex' => 'Number must be a valid Asterisk extension: digits only, pattern _XZN.! (e.g. _2XXX), or special s/i/t.',
        ];
        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->setAttributeNames(['pkey' => 'Number (DiD/CLiD)']);

        $validator->after(function ($validator) use ($request, $inboundroute, $clusterShortuid) {
            // Check if key exists within tenant (cluster); DB stores shortuid
            if ($inboundroute->where('pkey', '=', $request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey . " in this tenant.");
            }
            // Reject single "0" — not a valid DiD/CLiD
            $pkey = trim((string) $request->input('pkey', ''));
            if ($pkey === '0') {
                $validator->errors()->add('pkey', 'Number cannot be a single 0.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        move_request_to_model($request, $inboundroute, $this->updateableColumns);
        $inboundroute->cluster = $clusterShortuid;
        // Set pkey from request (may be "0" — valid DiD/CLiD; don't use empty() here)
        $inboundroute->pkey = trim((string) $request->input('pkey', ''));

        if (empty($inboundroute->trunkname)) {
            $inboundroute->trunkname = $inboundroute->pkey;
        }

        $inboundroute->id = generate_ksuid();
        $inboundroute->shortuid = generate_shortuid();
        $inboundroute->technology = $request->input('carrier', 'DiD');

        try {
            $inboundroute->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $inboundroute;
    }



/**
 * @param  Request
 * @param  InboundRoute
 * @return json response
 */
    public function update(Request $request, InboundRoute $inboundroute) {

        $validator = Validator::make($request->all(), $this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        move_request_to_model($request, $inboundroute, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid !== null) {
            $inboundroute->cluster = $clusterShortuid;
        }
        if ($request->has('carrier')) {
            $inboundroute->technology = $request->input('carrier');
        }

        // store the model if it has changed — update by id only (tenant-safe)
        try {
            if ($inboundroute->isDirty()) {
                $id = $inboundroute->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'InboundRoute id is missing'], 409);
                }
                $dirty = $inboundroute->getDirty();
                InboundRoute::where('id', $id)->update($dirty);
                $inboundroute->syncOriginal();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($inboundroute, 200);
        
    } 


/**
 * Delete  InboundRoute instance
 * @param  InboundRoute
 * @return 204
 */
    public function delete(InboundRoute $inboundroute) {
        $inboundroute->delete();

        return response()->json(null, 204);
    }
}
