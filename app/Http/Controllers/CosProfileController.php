<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\ClassOfService;
use App\Models\CosProfile;
use App\Models\CosProfileClosed;
use App\Models\CosProfileOpen;
use App\Models\Extension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class CosProfileController extends Controller
{
    use EnforcesClusterScope;

    private $updateableColumns = [
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'active' => 'in:YES,NO',
        // is_default is not client-updateable (Q6 fixed Default). Bootstrap on create only.
    ];

    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index()
    {
        $rows = $this->applyClusterScope(CosProfile::query())
            ->orderBy('cluster')
            ->orderBy('cname')
            ->orderBy('pkey')
            ->get();

        return $rows->map(fn (CosProfile $p) => $this->withRules($p));
    }

    public function show(CosProfile $cosprofile)
    {
        $this->assertModelClusterAllowed($cosprofile);

        return response()->json($this->withRules($cosprofile), 200);
    }

    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $rules = array_merge($this->updateableColumns, [
            'cluster' => 'required|exists:cluster,pkey',
            'cname' => 'required|string|max:128',
            'open_rules' => 'array|nullable',
            'open_rules.*' => 'string',
            'closed_rules' => 'array|nullable',
            'closed_rules.*' => 'string',
        ]);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $clusterShortuid) {
            $this->validateRuleLists($validator, $clusterShortuid, $request->input('open_rules', []), $request->input('closed_rules', []));
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $profile = new CosProfile;
        move_request_to_model($request, $profile, $this->updateableColumns);
        $profile->cluster = $clusterShortuid;
        $profile->cname = trim((string) $request->input('cname', ''));
        $profile->id = generate_ksuid();
        $profile->shortuid = generate_shortuid();
        $profile->pkey = $profile->shortuid;
        if (! $request->has('active') || trim((string) $request->input('active')) === '') {
            $profile->active = 'YES';
        }
        // Q6: fixed Default — new profiles are never elected default unless tenant has none yet.
        $profile->is_default = $this->tenantHasDefault($clusterShortuid) ? 'NO' : 'YES';

        try {
            DB::transaction(function () use ($profile, $request, $clusterShortuid) {
                $profile->save();
                $this->replaceRules(
                    $profile,
                    $clusterShortuid,
                    $request->input('open_rules', []),
                    $request->input('closed_rules', [])
                );
                set_commit_dirty();
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $this->withRules($profile->fresh());
    }

    public function update(Request $request, CosProfile $cosprofile)
    {
        $this->assertModelClusterAllowed($cosprofile);

        $rules = array_merge($this->updateableColumns, [
            'open_rules' => 'array|nullable',
            'open_rules.*' => 'string',
            'closed_rules' => 'array|nullable',
            'closed_rules.*' => 'string',
        ]);

        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'))
            ?? cluster_identifier_to_shortuid((string) $cosprofile->cluster)
            ?? (string) $cosprofile->cluster;

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $clusterShortuid, $cosprofile) {
            if ($request->has('is_default')) {
                $want = strtoupper(trim((string) $request->input('is_default')));
                $have = strtoupper(trim((string) ($cosprofile->is_default ?? 'NO')));
                if ($want !== $have) {
                    $validator->errors()->add(
                        'is_default',
                        'Default profile is fixed per tenant. Edit this profile’s rule lists to change default policy; do not move the Default flag.'
                    );
                }
            }
            if ($request->has('open_rules') || $request->has('closed_rules')) {
                $this->validateRuleLists(
                    $validator,
                    $clusterShortuid,
                    $request->input('open_rules', []),
                    $request->input('closed_rules', [])
                );
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $cosprofile, $this->updateableColumns);
        $reqCluster = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($reqCluster !== null) {
            $this->assertClusterAllowed($reqCluster);
            $cosprofile->cluster = $reqCluster;
            $clusterShortuid = $reqCluster;
        }
        if ($request->has('cname')) {
            $cosprofile->cname = trim((string) $request->input('cname', ''));
        }

        try {
            DB::transaction(function () use ($cosprofile, $request, $clusterShortuid) {
                if ($cosprofile->isDirty()) {
                    $id = $cosprofile->id;
                    if ($id === null || $id === '') {
                        throw new \RuntimeException('CoS profile id is missing');
                    }
                    CosProfile::where('id', $id)->update($cosprofile->getDirty());
                    $cosprofile->syncOriginal();
                }
                if ($request->has('open_rules') || $request->has('closed_rules')) {
                    $open = $request->has('open_rules') ? $request->input('open_rules', []) : $this->currentOpen($cosprofile);
                    $closed = $request->has('closed_rules') ? $request->input('closed_rules', []) : $this->currentClosed($cosprofile);
                    $this->replaceRules($cosprofile, $clusterShortuid, $open, $closed);
                }
                set_commit_dirty();
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($this->withRules($cosprofile->fresh()), 200);
    }

    public function delete(CosProfile $cosprofile)
    {
        $this->assertModelClusterAllowed($cosprofile);

        $aliases = cluster_identifier_aliases($cosprofile->cluster);
        if ($aliases === []) {
            $aliases = [(string) $cosprofile->cluster];
        }
        $inUse = Extension::whereIn('cluster', $aliases)
            ->where('cos_profile', $cosprofile->pkey)
            ->count();
        if ($inUse > 0) {
            return Response::json([
                'Error' => "Profile is assigned to {$inUse} extension(s). Reassign phones before delete.",
            ], 409);
        }
        if (strtoupper(trim((string) $cosprofile->is_default)) === 'YES') {
            return Response::json([
                'Error' => 'Cannot delete the tenant Default profile. Edit its rule lists to change default policy, or reassign phones and keep this profile.',
            ], 409);
        }

        try {
            DB::transaction(function () use ($cosprofile) {
                CosProfileOpen::where('profile_pkey', $cosprofile->pkey)->delete();
                CosProfileClosed::where('profile_pkey', $cosprofile->pkey)->delete();
                $cosprofile->delete();
                set_commit_dirty();
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json(null, 204);
    }

    private function withRules(CosProfile $profile): array
    {
        $arr = $profile->toArray();
        $arr['open_rules'] = $this->currentOpen($profile);
        $arr['closed_rules'] = $this->currentClosed($profile);

        return $arr;
    }

    /** @return list<string> */
    private function currentOpen(CosProfile $profile): array
    {
        return CosProfileOpen::where('profile_pkey', $profile->pkey)
            ->orderBy('cos_pkey')
            ->pluck('cos_pkey')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function currentClosed(CosProfile $profile): array
    {
        return CosProfileClosed::where('profile_pkey', $profile->pkey)
            ->orderBy('cos_pkey')
            ->pluck('cos_pkey')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }

    private function tenantHasDefault(string $clusterShortuid): bool
    {
        $aliases = cluster_identifier_aliases($clusterShortuid);
        if ($aliases === []) {
            $aliases = [$clusterShortuid];
        }

        return CosProfile::whereIn('cluster', $aliases)
            ->whereRaw("upper(trim(is_default)) = 'YES'")
            ->exists();
    }

    /**
     * @param  list<mixed>  $open
     * @param  list<mixed>  $closed
     */
    private function validateRuleLists($validator, string $clusterShortuid, $open, $closed): void
    {
        $aliases = cluster_identifier_aliases($clusterShortuid);
        if ($aliases === []) {
            $aliases = [$clusterShortuid];
        }
        $valid = ClassOfService::whereIn('cluster', $aliases)->pluck('pkey')->all();
        $validSet = array_fill_keys($valid, true);

        foreach (['open_rules' => $open, 'closed_rules' => $closed] as $field => $list) {
            if (! is_array($list)) {
                continue;
            }
            $seen = [];
            foreach ($list as $i => $cosPkey) {
                $cosPkey = trim((string) $cosPkey);
                if ($cosPkey === '') {
                    $validator->errors()->add("{$field}.{$i}", 'Empty CoS rule key.');
                    continue;
                }
                if (! isset($validSet[$cosPkey])) {
                    $validator->errors()->add($field, "Unknown CoS rule for this tenant: {$cosPkey}");
                }
                if (isset($seen[$cosPkey])) {
                    $validator->errors()->add("{$field}.{$i}", 'Duplicate rule in list.');
                }
                $seen[$cosPkey] = true;
            }
        }
    }

    /**
     * @param  list<mixed>  $open
     * @param  list<mixed>  $closed
     */
    private function replaceRules(CosProfile $profile, string $clusterShortuid, $open, $closed): void
    {
        CosProfileOpen::where('profile_pkey', $profile->pkey)->delete();
        CosProfileClosed::where('profile_pkey', $profile->pkey)->delete();

        if (is_array($open)) {
            foreach ($open as $cosPkey) {
                $cosPkey = trim((string) $cosPkey);
                if ($cosPkey === '') {
                    continue;
                }
                CosProfileOpen::create([
                    'id' => generate_ksuid(),
                    'cluster' => $clusterShortuid,
                    'active' => 'YES',
                    'profile_pkey' => $profile->pkey,
                    'cos_pkey' => $cosPkey,
                ]);
            }
        }
        if (is_array($closed)) {
            foreach ($closed as $cosPkey) {
                $cosPkey = trim((string) $cosPkey);
                if ($cosPkey === '') {
                    continue;
                }
                CosProfileClosed::create([
                    'id' => generate_ksuid(),
                    'cluster' => $clusterShortuid,
                    'active' => 'YES',
                    'profile_pkey' => $profile->pkey,
                    'cos_pkey' => $cosPkey,
                ]);
            }
        }
    }
}
