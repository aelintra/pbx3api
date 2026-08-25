<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\ClidBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Tenant CLID block list — inbound caller ID reject (Phase 1: SPA CRUD only).
 * Table clid_block; pkey = normalized digits-only CLID unique per cluster.
 */
class ClidBlockController extends Controller
{
    use EnforcesClusterScope;

    private $updateableColumns = [
        'cluster' => 'exists:cluster,pkey',
        'active' => 'in:YES,NO',
        'action' => 'in:hangup',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
    ];

    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index()
    {
        $rows = $this->applyClusterScope(ClidBlock::query())
            ->orderBy('cluster')
            ->orderBy('pkey')
            ->get();
        attach_tenant_pkey_to_collection($rows);

        return $rows;
    }

    public function show(ClidBlock $clidblock)
    {
        $this->assertModelClusterAllowed($clidblock);

        return response()->json($clidblock, 200);
    }

    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $normalized = normalize_clid_block_digits($request->input('pkey'));
        if ($normalized === null) {
            return response()->json([
                'pkey' => ['Caller ID must be at least 6 digits (non-digit characters are stripped).'],
            ], 422);
        }

        $rules = array_merge($this->updateableColumns, [
            'cluster' => 'required|exists:cluster,pkey',
            'pkey' => 'required|string',
        ]);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($clusterShortuid, $normalized) {
            if (ClidBlock::where('pkey', $normalized)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That caller ID is already blocked for this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $row = new ClidBlock;
        move_request_to_model($request, $row, $this->updateableColumns);
        $row->cluster = $clusterShortuid;
        $row->pkey = $normalized;
        $row->id = generate_ksuid();
        $row->shortuid = generate_shortuid();
        if ($row->action === null || $row->action === '') {
            $row->action = 'hangup';
        }
        $this->stampAudit($row, true);

        try {
            $row->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        attach_tenant_pkey_to_collection(collect([$row]));

        return $row;
    }

    public function update(Request $request, ClidBlock $clidblock)
    {
        $this->assertModelClusterAllowed($clidblock);

        $validator = Validator::make($request->all(), $this->updateableColumns);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $clidblock, $this->updateableColumns);
        if ($request->has('cluster')) {
            $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
            if ($clusterShortuid === null) {
                return response()->json(['cluster' => ['Invalid cluster.']], 422);
            }
            $this->assertClusterAllowed($clusterShortuid);
            $clidblock->cluster = $clusterShortuid;
        }

        $this->stampAudit($clidblock, false);

        try {
            if ($clidblock->isDirty()) {
                $id = $clidblock->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'CLID block id is missing'], 409);
                }
                ClidBlock::where('id', $id)->update($clidblock->getDirty());
                $clidblock->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        attach_tenant_pkey_to_collection(collect([$clidblock]));

        return response()->json($clidblock, 200);
    }

    public function delete(ClidBlock $clidblock)
    {
        $this->assertModelClusterAllowed($clidblock);
        $clidblock->delete();

        return response()->json(null, 204);
    }

    private function stampAudit(ClidBlock $row, bool $isCreate): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $user = request()->user('sanctum') ?? auth('sanctum')->user();
        $who = $user && $user->email ? (string) $user->email : 'system';
        if ($isCreate) {
            $row->z_created = $now;
        }
        $row->z_updated = $now;
        $row->z_updater = $who;
    }
}
