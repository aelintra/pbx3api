<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\DialAlias;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Dial prefixes (tenant short dial slice A): CRUD only — no GenAst/CAGI/SBC yet.
 * Product name: dial prefix; table/resource: dialalias / dialaliases.
 */
class DialAliasController extends Controller
{
    use EnforcesClusterScope;

    private $updateableColumns = [
        'pkey' => 'regex:/^\d{2,4}$/',
        'active' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'target_cluster' => 'string',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
    ];

    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index()
    {
        $rows = $this->applyClusterScope(DialAlias::query())
            ->orderBy('cluster')
            ->orderBy('pkey')
            ->get();
        attach_tenant_pkey_to_collection($rows);
        $this->attachTargetTenantPkey($rows);

        return $rows;
    }

    public function show(DialAlias $dialalias)
    {
        $this->assertModelClusterAllowed($dialalias);
        attach_tenant_pkey_to_collection(collect([$dialalias]));
        $this->attachTargetTenantPkey(collect([$dialalias]));

        return response()->json($dialalias, 200);
    }

    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $targetShortuid = cluster_identifier_to_shortuid($request->input('target_cluster'));
        if ($targetShortuid === null) {
            return response()->json(['target_cluster' => ['Invalid or missing target tenant.']], 422);
        }
        $this->assertClusterAllowed($targetShortuid);

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => ['required', 'regex:/^\d{2,4}$/'],
            'cluster' => 'required|exists:cluster,pkey',
            'target_cluster' => 'required|string',
        ]);

        $validator = Validator::make($request->all(), $createRules);

        $validator->after(function ($validator) use ($request, $clusterShortuid, $targetShortuid) {
            $alias = trim((string) $request->input('pkey', ''));
            if ($alias !== '' && DialAlias::where('pkey', $alias)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That dial prefix is already in use in this tenant.');
            }
            if ($targetShortuid !== null && $targetShortuid === $clusterShortuid) {
                $validator->errors()->add('target_cluster', 'Target tenant must be different from the calling tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $row = new DialAlias;
        move_request_to_model($request, $row, $createRules);
        $row->cluster = $clusterShortuid;
        $row->target_cluster = $targetShortuid;
        $row->pkey = trim((string) $request->input('pkey'));
        $row->id = generate_ksuid();
        $row->shortuid = generate_shortuid();

        try {
            $row->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        attach_tenant_pkey_to_collection(collect([$row]));
        $this->attachTargetTenantPkey(collect([$row]));

        return $row;
    }

    public function update(Request $request, DialAlias $dialalias)
    {
        $this->assertModelClusterAllowed($dialalias);

        $validator = Validator::make($request->all(), $this->updateableColumns);

        $validator->after(function ($validator) use ($request, $dialalias) {
            $newPkey = $request->has('pkey') ? trim((string) $request->input('pkey', '')) : null;
            $clusterShortuid = $dialalias->cluster;
            if ($request->filled('cluster')) {
                $resolved = cluster_identifier_to_shortuid($request->input('cluster'));
                if ($resolved !== null) {
                    $clusterShortuid = $resolved;
                }
            }

            if ($newPkey !== null && $newPkey !== '' && $newPkey !== (string) $dialalias->pkey) {
                if (DialAlias::where('pkey', $newPkey)->where('cluster', $clusterShortuid)->where('id', '!=', $dialalias->id)->exists()) {
                    $validator->errors()->add('pkey', 'That dial prefix is already in use in this tenant.');
                }
            }

            $targetShortuid = $dialalias->target_cluster;
            if ($request->filled('target_cluster')) {
                $resolvedTarget = cluster_identifier_to_shortuid($request->input('target_cluster'));
                if ($resolvedTarget === null) {
                    $validator->errors()->add('target_cluster', 'Invalid target tenant.');
                } else {
                    $targetShortuid = $resolvedTarget;
                }
            }

            if ($targetShortuid !== null && $clusterShortuid !== null && $targetShortuid === $clusterShortuid) {
                $validator->errors()->add('target_cluster', 'Target tenant must be different from the calling tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $dialalias, $this->updateableColumns);

        if ($request->filled('cluster')) {
            $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
            if ($clusterShortuid === null) {
                return response()->json(['cluster' => ['Invalid cluster.']], 422);
            }
            $this->assertClusterAllowed($clusterShortuid);
            $dialalias->cluster = $clusterShortuid;
        }

        if ($request->filled('target_cluster')) {
            $targetShortuid = cluster_identifier_to_shortuid($request->input('target_cluster'));
            if ($targetShortuid === null) {
                return response()->json(['target_cluster' => ['Invalid target tenant.']], 422);
            }
            $this->assertClusterAllowed($targetShortuid);
            $dialalias->target_cluster = $targetShortuid;
        }

        if ($request->has('pkey')) {
            $dialalias->pkey = trim((string) $request->input('pkey', ''));
        }

        try {
            if ($dialalias->isDirty()) {
                $id = $dialalias->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Dial prefix id is missing'], 409);
                }
                DialAlias::where('id', $id)->update($dialalias->getDirty());
                $dialalias->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        $fresh = $dialalias->fresh();
        attach_tenant_pkey_to_collection(collect([$fresh]));
        $this->attachTargetTenantPkey(collect([$fresh]));

        return response()->json($fresh, 200);
    }

    public function delete(DialAlias $dialalias)
    {
        $this->assertModelClusterAllowed($dialalias);
        $dialalias->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  \Illuminate\Support\Collection|iterable  $collection
     */
    private function attachTargetTenantPkey($collection): void
    {
        $map = [];
        try {
            foreach (DB::table('cluster')->get(['id', 'shortuid', 'pkey']) as $row) {
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
            // ignore
        }

        foreach ($collection as $item) {
            $t = $item->target_cluster ?? null;
            $item->target_tenant_pkey = ($t !== null && $t !== '') ? ($map[(string) $t] ?? $t) : $t;
        }
    }
}
