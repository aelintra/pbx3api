<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AgentController extends Controller
{
    //

    // agent table (sqlite_create_tenant.sql). pkey = agent number 1000–9999, unique per tenant. Exclude id, shortuid, z_*, name (deprecated).
    private $updateableColumns = [
        'pkey' => 'nullable|integer|min:1000|max:9999',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'extlen' => 'integer|nullable',
        'passwd' => 'nullable|integer|min:1001|max:9999',
        'queue1' => 'exists:queue,pkey|nullable',
        'queue2' => 'exists:queue,pkey|nullable',
        'queue3' => 'exists:queue,pkey|nullable',
        'queue4' => 'exists:queue,pkey|nullable',
        'queue5' => 'exists:queue,pkey|nullable',
        'queue6' => 'exists:queue,pkey|nullable',
    ];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

/**
 *
 * @return Ring Groups
 */
    public function index (Agent $agent) {

    	return Agent::orderBy('pkey','asc')->get();
    }

    /** Export agents list as PDF. Same dataset as index with tenant_pkey resolved. */
    public function exportPdf()
    {
        $agents = Agent::orderBy('pkey', 'asc')->get();
        attach_tenant_pkey_to_collection($agents);
        return Pdf::loadView('exports.agents-pdf', ['agents' => $agents])
            ->setPaper('a4', 'landscape')
            ->download('agents.pdf');
    }

/**
 * Return named queue model instance
 * 
 * @param  Agent
 * @return Agent object
 */
    public function show (Agent $agent) {

    	return response()->json($agent, 200);
    }

/**
 * Create a new Agent instance
 * 
 * @param  Request
 * @return New Did
 */
    public function save(Request $request) {

        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => 'required|integer|min:1000|max:9999',
            'cluster' => 'required|exists:cluster,pkey',
            'passwd' => 'required|integer|min:1001|max:9999',
        ]);

        $agent = new Agent;

        $validator = Validator::make($request->all(), $createRules);

        $validator->after(function ($validator) use ($request, $agent, $clusterShortuid) {
            if (Agent::where('pkey', '=', $request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That agent number is already in use in this tenant.');
                return;
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $agent, $this->updateableColumns);
        $agent->cluster = $clusterShortuid;

        $agent->id = generate_ksuid();
        $agent->shortuid = generate_shortuid();

        try {
            $agent->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $agent;
    }

/**
 * @param  Request
 * @param  Agent
 * @return json response
 */
    public function update(Request $request, Agent $agent) {

        $validator = Validator::make($request->all(), $this->updateableColumns);

        $validator->after(function ($validator) use ($request, $agent) {
            $pkeySubmitted = $request->input('pkey');
            if ($pkeySubmitted !== null && (string) $pkeySubmitted !== (string) $agent->getAttribute('pkey')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster')) ?? $agent->cluster;
                if ($clusterShortuid !== null && Agent::where('pkey', $pkeySubmitted)->where('cluster', $clusterShortuid)->where('id', '!=', $agent->id)->exists()) {
                    $validator->errors()->add('pkey', 'That agent number is already in use in this tenant.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $agent, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $agent->cluster = $clusterShortuid;
        }

        try {
            if ($agent->isDirty()) {
                $id = $agent->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Agent id is missing'], 409);
                }
                $dirty = $agent->getDirty();
                Agent::where('id', $id)->update($dirty);
                $agent->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($agent, 200);
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(Agent $agent) {
        $agent->delete();

        return response()->json(null, 204);
    }

}
