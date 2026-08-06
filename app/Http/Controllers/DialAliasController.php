<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\DialAlias;
use App\Services\Fleet\ManagedDialAliasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Dial prefixes (tenant short dial A′ Q14): target is tenant FQDN (not local cluster shortuid-only).
 * Product name: dial prefix; table/resource: dialalias / dialaliases.
 * Instance admin only (abilities:admin). Tenant panel users cannot manage cross-tenant wiring.
 * C2: managed (source=cohort) rows are Sanctum read-only; when PBX3_DIAL_COHORT, Sanctum
 * cannot invent cross-tenant prefixes (403 → Fleet → Site Groups).
 */
class DialAliasController extends Controller
{
    use EnforcesClusterScope;

    /** FQDN: lowercase labels + TLD; requires at least one dot (rejects bare shortuid). */
    private const TARGET_FQDN_REGEX = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    private const SITE_GROUPS_HINT = 'Edit dial prefixes in Fleet → Site Groups.';

    private $updateableColumns = [
        'pkey' => 'regex:/^\d{2,4}$/',
        'active' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'target_fqdn' => 'string',
        'target_cluster' => 'nullable|string',
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
        $this->attachTargetMeta($rows);

        return $rows;
    }

    public function show(DialAlias $dialalias)
    {
        $this->assertModelClusterAllowed($dialalias);
        attach_tenant_pkey_to_collection(collect([$dialalias]));
        $this->attachTargetMeta(collect([$dialalias]));

        return response()->json($dialalias, 200);
    }

    public function save(Request $request)
    {
        if ($deny = $this->sanctumCrossTenantDeny()) {
            return $deny;
        }

        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $targetFqdn = $this->normalizeTenantFqdn($request->input('target_fqdn'));
        if ($targetFqdn === null) {
            return response()->json([
                'target_fqdn' => ['Target must be a full tenant FQDN (e.g. sister.pbx3.com), not a shortuid or instance host.'],
            ], 422);
        }

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => ['required', 'regex:/^\d{2,4}$/'],
            'cluster' => 'required|exists:cluster,pkey',
            'target_fqdn' => 'required|string',
        ]);

        $validator = Validator::make($request->all(), $createRules);

        $validator->after(function ($validator) use ($request, $clusterShortuid, $targetFqdn) {
            $alias = trim((string) $request->input('pkey', ''));
            if ($alias !== '' && DialAlias::where('pkey', $alias)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That dial prefix is already in use in this tenant.');
            }
            $selfErr = $this->targetSelfConflictMessage($clusterShortuid, $targetFqdn);
            if ($selfErr !== null) {
                $validator->errors()->add('target_fqdn', $selfErr);
            }
            $instErr = $this->instanceFqdnConflictMessage($targetFqdn);
            if ($instErr !== null) {
                $validator->errors()->add('target_fqdn', $instErr);
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $row = new DialAlias;
        move_request_to_model($request, $row, $createRules);
        $row->cluster = $clusterShortuid;
        $row->target_fqdn = $targetFqdn;
        $row->target_cluster = $this->resolveOptionalTargetCluster(
            $request->input('target_cluster'),
            $targetFqdn
        );
        $row->pkey = trim((string) $request->input('pkey'));
        $row->source = ManagedDialAliasService::SOURCE_MANUAL;
        $row->cohort_id = null;
        $row->id = generate_ksuid();
        $row->shortuid = generate_shortuid();

        try {
            $row->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        attach_tenant_pkey_to_collection(collect([$row]));
        $this->attachTargetMeta(collect([$row]));

        return $row;
    }

    public function update(Request $request, DialAlias $dialalias)
    {
        $this->assertModelClusterAllowed($dialalias);

        if ($deny = $this->sanctumManagedDeny($dialalias)) {
            return $deny;
        }
        if ($deny = $this->sanctumCrossTenantDeny()) {
            return $deny;
        }

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

            $targetFqdn = $dialalias->target_fqdn;
            if ($request->has('target_fqdn')) {
                $normalized = $this->normalizeTenantFqdn($request->input('target_fqdn'));
                if ($normalized === null) {
                    $validator->errors()->add(
                        'target_fqdn',
                        'Target must be a full tenant FQDN (e.g. sister.pbx3.com), not a shortuid or instance host.'
                    );
                    $targetFqdn = null;
                } else {
                    $targetFqdn = $normalized;
                }
            }

            if ($targetFqdn !== null && $clusterShortuid !== null) {
                $selfErr = $this->targetSelfConflictMessage($clusterShortuid, $targetFqdn);
                if ($selfErr !== null) {
                    $validator->errors()->add('target_fqdn', $selfErr);
                }
                $instErr = $this->instanceFqdnConflictMessage($targetFqdn);
                if ($instErr !== null) {
                    $validator->errors()->add('target_fqdn', $instErr);
                }
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

        if ($request->has('target_fqdn')) {
            $normalized = $this->normalizeTenantFqdn($request->input('target_fqdn'));
            if ($normalized === null) {
                return response()->json([
                    'target_fqdn' => ['Target must be a full tenant FQDN (e.g. sister.pbx3.com).'],
                ], 422);
            }
            $dialalias->target_fqdn = $normalized;
        }

        if ($request->has('target_cluster') || $request->has('target_fqdn')) {
            $pin = $request->has('target_cluster')
                ? $request->input('target_cluster')
                : $dialalias->target_cluster;
            $dialalias->target_cluster = $this->resolveOptionalTargetCluster(
                $pin,
                (string) $dialalias->target_fqdn
            );
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
        $this->attachTargetMeta(collect([$fresh]));

        return response()->json($fresh, 200);
    }

    public function delete(DialAlias $dialalias)
    {
        $this->assertModelClusterAllowed($dialalias);

        if ($deny = $this->sanctumManagedDeny($dialalias)) {
            return $deny;
        }
        if ($deny = $this->sanctumCrossTenantDeny()) {
            return $deny;
        }

        $dialalias->delete();

        return response()->json(null, 204);
    }

    /**
     * Managed Site Group rows are always Sanctum read-only.
     */
    private function sanctumManagedDeny(DialAlias $dialalias): ?\Illuminate\Http\JsonResponse
    {
        if (ManagedDialAliasService::isManaged($dialalias)) {
            return response()->json([
                'message' => 'This dial prefix is managed by a Site Group. '.self::SITE_GROUPS_HINT,
                'source' => 'cohort',
                'cohort_id' => $dialalias->cohort_id,
            ], 403);
        }

        return null;
    }

    /**
     * When PBX3_DIAL_COHORT is on, Sanctum must not invent/edit cross-tenant prefixes.
     */
    private function sanctumCrossTenantDeny(): ?\Illuminate\Http\JsonResponse
    {
        if (! ManagedDialAliasService::cohortFeatureOn()) {
            return null;
        }

        return response()->json([
            'message' => 'Site Groups own cross-tenant dial prefixes. '.self::SITE_GROUPS_HINT,
            'feature' => 'dial_cohort',
        ], 403);
    }

    /**
     * Strip paste junk → lowercase host; null if not a multi-label FQDN.
     */
    private function normalizeTenantFqdn($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = strtolower(trim((string) $raw));
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        if ($s === '') {
            return null;
        }

        if (str_contains($s, '://')) {
            $host = parse_url($s, PHP_URL_HOST);
            $s = is_string($host) && $host !== '' ? $host : preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $s);
            $s = strtolower(explode('/', (string) $s)[0] ?? '');
        } elseif (str_contains($s, '/') && ! str_contains($s, ' ')) {
            $s = strtolower(explode('/', $s)[0] ?? '');
        }

        if (str_contains($s, '@')) {
            $s = strtolower(explode('@', $s)[1] ?? $s);
        }

        $s = preg_replace('/:\d+$/', '', $s) ?? $s;
        $s = rtrim($s, '.');

        if ($s === '' || ! str_contains($s, '.')) {
            return null;
        }
        if (! preg_match(self::TARGET_FQDN_REGEX, $s)) {
            return null;
        }

        return $s;
    }

    /**
     * Optional shortuid pin: accept local identifier if known; else match FQDN in local cluster; else null.
     * Never requires target to exist locally.
     */
    private function resolveOptionalTargetCluster($raw, string $targetFqdn): ?string
    {
        if ($raw !== null && trim((string) $raw) !== '') {
            $resolved = cluster_identifier_to_shortuid($raw);
            if ($resolved !== null) {
                return $resolved;
            }
            // Non-local or unknown pin — store nothing rather than garbage pkey
        }

        try {
            $row = DB::table('cluster')
                ->whereRaw('LOWER(TRIM(fqdn)) = ?', [strtolower($targetFqdn)])
                ->first(['shortuid']);
            if ($row && ! empty($row->shortuid)) {
                return (string) $row->shortuid;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    private function targetSelfConflictMessage(string $callingShortuid, string $targetFqdn): ?string
    {
        try {
            $calling = DB::table('cluster')
                ->where('shortuid', $callingShortuid)
                ->orWhere('pkey', $callingShortuid)
                ->orWhere('id', $callingShortuid)
                ->first(['shortuid', 'fqdn']);
            if ($calling) {
                $callFqdn = strtolower(trim((string) ($calling->fqdn ?? '')));
                if ($callFqdn !== '' && $callFqdn === $targetFqdn) {
                    return 'Target tenant FQDN must differ from the calling tenant.';
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    private function instanceFqdnConflictMessage(string $targetFqdn): ?string
    {
        try {
            $g = DB::table('globals')->first(['fqdn', 'domain']);
            if (! $g) {
                return null;
            }
            foreach (['fqdn', 'domain'] as $col) {
                $v = strtolower(trim((string) ($g->{$col} ?? '')));
                if ($v !== '' && $v === $targetFqdn) {
                    return 'Target must be a tenant FQDN, not this instance hostname.';
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection|iterable  $collection
     */
    private function attachTargetMeta($collection): void
    {
        $byShort = [];
        $byFqdn = [];
        try {
            foreach (DB::table('cluster')->get(['id', 'shortuid', 'pkey', 'fqdn']) as $row) {
                $pkey = $row->pkey ?? $row->shortuid ?? null;
                $fqdn = strtolower(trim((string) ($row->fqdn ?? '')));
                if (isset($row->id)) {
                    $byShort[(string) $row->id] = $pkey;
                }
                if (isset($row->shortuid)) {
                    $byShort[(string) $row->shortuid] = $pkey;
                }
                if (isset($row->pkey)) {
                    $byShort[(string) $row->pkey] = $pkey;
                }
                if ($fqdn !== '') {
                    $byFqdn[$fqdn] = $pkey;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        foreach ($collection as $item) {
            $t = $item->target_cluster ?? null;
            $fqdn = strtolower(trim((string) ($item->target_fqdn ?? '')));
            $label = null;
            if ($t !== null && $t !== '') {
                $label = $byShort[(string) $t] ?? null;
            }
            if ($label === null && $fqdn !== '') {
                $label = $byFqdn[$fqdn] ?? null;
            }
            $item->target_tenant_pkey = $label ?? ($t !== null && $t !== '' ? $t : null);
        }
    }
}
