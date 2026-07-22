<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\ClassOfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class ClassOfServiceController extends Controller
{
    use EnforcesClusterScope;

    // cos table (sqlite_create_tenant.sql). pkey = identity-only (not updateable). orideopen/orideclosed not updateable.
    private $updateableColumns = [
        'active' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'dialplan' => 'required|string',
        'defaultopen' => 'in:YES,NO',
        'defaultclosed' => 'in:YES,NO',
    ];

    /** Return column names that are updateable (for schema metadata). */
    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index(ClassOfService $classofservice)
    {
        return $this->applyClusterScope(ClassOfService::query())->orderBy('pkey', 'asc')->get();
    }

    public function show(ClassOfService $classofservice)
    {
        $this->assertModelClusterAllowed($classofservice);
        return response()->json($classofservice, 200);
    }

    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $rules = array_merge($this->updateableColumns, [
            'pkey' => 'required|alpha_dash',
            'cluster' => 'required|exists:cluster,pkey',
        ]);

        $classofservice = new ClassOfService;
        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $clusterShortuid) {
            $pkey = $request->input('pkey');
            if ($pkey !== null && ClassOfService::where('pkey', $pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That CoS key is already in use in this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $classofservice, array_merge($this->updateableColumns, ['pkey' => 'required|alpha_dash']));
        $classofservice->cluster = $clusterShortuid;
        $classofservice->id = generate_ksuid();
        $classofservice->shortuid = generate_shortuid();

        try {
            $classofservice->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $classofservice;
    }

    public function update(Request $request, ClassOfService $classofservice)
    {
        $this->assertModelClusterAllowed($classofservice);
        $validator = Validator::make($request->all(), $this->updateableColumns);

        $validator->after(function ($validator) use ($request, $classofservice) {
            if ($request->has('cluster')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
                if ($clusterShortuid !== null && $clusterShortuid !== $classofservice->cluster) {
                    if (ClassOfService::where('pkey', $classofservice->pkey)->where('cluster', $clusterShortuid)->where('id', '!=', $classofservice->id)->exists()) {
                        $validator->errors()->add('cluster', 'That tenant already has a CoS rule with this key.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $classofservice, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $this->assertClusterAllowed($clusterShortuid);
            $classofservice->cluster = $clusterShortuid;
        }

        try {
            if ($classofservice->isDirty()) {
                $id = $classofservice->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Class of Service id is missing'], 409);
                }
                $dirty = $classofservice->getDirty();
                ClassOfService::where('id', $id)->update($dirty);
                $classofservice->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($classofservice, 200);
    }

    public function delete(ClassOfService $classofservice)
    {
        $this->assertModelClusterAllowed($classofservice);
        $classofservice->delete();
        return response()->json(null, 204);
    }
}
