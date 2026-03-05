<?php

namespace App\Http\Controllers;

use App\Models\Route;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class RouteController extends Controller
{
    //

    // route table (full_schema.sql). Exclude id, pkey, shortuid, z_*.
    private $updateableColumns = [
        'active' => 'in:YES,NO',
        'alternate' => 'string|nullable',
        'auth' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'dialplan' => 'string|nullable',
        'path1' => 'exists:trunks,pkey|nullable',
        'path2' => 'exists:trunks,pkey|nullable',
        'path3' => 'exists:trunks,pkey|nullable',
        'path4' => 'exists:trunks,pkey|nullable',
        'route' => 'string|nullable',
        'strategy' => 'in:hunt,balance',
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
    public function index (Route $route) {

    	return Route::orderBy('pkey','asc')->get();
    }

    /** Export routes list as PDF. Same dataset as index with tenant_pkey resolved. */
    public function exportPdf()
    {
        $routes = Route::orderBy('pkey', 'asc')->get();
        attach_tenant_pkey_to_collection($routes);
        return Pdf::loadView('exports.routes-pdf', ['routes' => $routes])
            ->setPaper('a4', 'landscape')
            ->download('routes.pdf');
    }

/**
 * Return named queue model instance
 * 
 * @param  Route
 * @return Route object
 */
    public function show (Route $route) {

    	return response()->json($route, 200);
    }

/**
 * Create a new queue instance
 * 
 * @param  Request
 * @return New Did
 */
    public function save(Request $request) {

        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

        $this->normalizePathInputs($request);

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => 'required',
            'cluster' => 'required|exists:cluster,pkey',
        ]);

        $route = new Route;

        $validator = Validator::make($request->all(), $createRules);

        $validator->after(function ($validator) use ($request, $clusterShortuid) {
            if (Route::where('pkey', '=', $request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That route name is already in use in this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $route, $this->updateableColumns);
        $route->cluster = $clusterShortuid;

        $route->id = generate_ksuid();
        $route->shortuid = generate_shortuid();

// create the model         
        try {
            $route->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $route;
    }

/**
 * @param  Request
 * @param  Route
 * @return json response
 */
    public function update(Request $request, Route $route) {

        $this->normalizePathInputs($request);

        $validator = Validator::make($request->all(), $this->updateableColumns);

        $validator->after(function ($validator) use ($request, $route) {
            $pkeySubmitted = $request->input('pkey');
            if ($pkeySubmitted !== null && (string) $pkeySubmitted !== (string) $route->getAttribute('pkey')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster')) ?? $route->cluster;
                if ($clusterShortuid !== null && Route::where('pkey', $pkeySubmitted)->where('cluster', $clusterShortuid)->where('id', '!=', $route->id)->exists()) {
                    $validator->errors()->add('pkey', 'That route name is already in use in this tenant.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $route, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $route->cluster = $clusterShortuid;
        }


// store the model if it has changed — update by id only (tenant-safe)
        try {
            if ($route->isDirty()) {
                $id = $route->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Route id is missing'], 409);
                }
                $dirty = $route->getDirty();
                Route::where('id', $id)->update($dirty);
                $route->syncOriginal();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($route, 200);
        
    } 


/**
 * Delete  Route instance
 * @param  Route
 * @return 204
 */
    public function delete(Route $route) {
        $route->delete();

        return response()->json(null, 204);
    }

    /**
     * Normalize path1-path4 only when the client explicitly sends the key.
     * Empty string or 'None' becomes null so "no trunk" passes validation (nullable).
     * If the key is absent from the request, we do not add it — partial updates
     * (e.g. only sending "active") do not clear path1-path4; other API users
     * do not need to resend path values unless they intend to change them.
     */
    private function normalizePathInputs(Request $request): void
    {
        foreach (['path1', 'path2', 'path3', 'path4'] as $key) {
            if (! $request->has($key)) {
                continue;
            }
            $v = $request->input($key);
            if ($v === null || $v === '' || (is_string($v) && strtolower(trim($v)) === 'none')) {
                $request->merge([$key => null]);
            }
        }
    }

}
