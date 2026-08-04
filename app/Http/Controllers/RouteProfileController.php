<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\RouteProfile;
use App\Models\RouteProfileLine;
use App\Support\ScheduleModes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class RouteProfileController extends Controller
{
    use EnforcesClusterScope;

    private $updateableColumns = [
        'cluster' => 'exists:cluster,pkey',
        'name' => 'string|nullable',
        'default_mode' => 'string|nullable',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
    ];

    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index()
    {
        $rows = $this->applyClusterScope(RouteProfile::query())
            ->orderBy('cluster')
            ->orderBy('name')
            ->orderBy('shortuid')
            ->get();

        return $rows->map(fn (RouteProfile $p) => $this->withLines($p));
    }

    public function show(RouteProfile $routeprofile)
    {
        $this->assertModelClusterAllowed($routeprofile);

        return response()->json($this->withLines($routeprofile), 200);
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
            'name' => 'required|string|max:128',
            'default_mode' => ScheduleModes::validationRule(true),
            'lines' => 'array|nullable',
            'lines.*.mode' => ScheduleModes::validationRule(false),
            'lines.*.destination' => 'required|string|max:128',
        ]);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request) {
            $this->validateLines($validator, $request->input('lines', []));
            $dm = ScheduleModes::normalize($request->input('default_mode'), 'open');
            if (! ScheduleModes::isValid($dm)) {
                $validator->errors()->add('default_mode', 'Invalid mode string.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $profile = new RouteProfile;
        move_request_to_model($request, $profile, $this->updateableColumns);
        $profile->cluster = $clusterShortuid;
        $profile->default_mode = ScheduleModes::normalize($request->input('default_mode'), 'open');
        $profile->name = trim((string) $request->input('name', ''));
        $profile->id = generate_ksuid();
        $profile->shortuid = generate_shortuid();
        $profile->pkey = $profile->shortuid;

        try {
            DB::transaction(function () use ($profile, $request, $clusterShortuid) {
                $profile->save();
                $this->replaceLines($profile, $clusterShortuid, $request->input('lines', []));
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $this->withLines($profile->fresh());
    }

    public function update(Request $request, RouteProfile $routeprofile)
    {
        $this->assertModelClusterAllowed($routeprofile);

        $rules = array_merge($this->updateableColumns, [
            'default_mode' => ScheduleModes::validationRule(true),
            'lines' => 'array|nullable',
            'lines.*.mode' => ScheduleModes::validationRule(false),
            'lines.*.destination' => 'required_with:lines|string|max:128',
        ]);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request) {
            if ($request->has('lines')) {
                $this->validateLines($validator, $request->input('lines', []));
            }
            if ($request->has('default_mode')) {
                $dm = ScheduleModes::normalize($request->input('default_mode'), 'open');
                if (! ScheduleModes::isValid($dm)) {
                    $validator->errors()->add('default_mode', 'Invalid mode string.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $routeprofile, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $this->assertClusterAllowed($clusterShortuid);
            $routeprofile->cluster = $clusterShortuid;
        } else {
            $clusterShortuid = (string) $routeprofile->cluster;
            $resolved = cluster_identifier_to_shortuid($clusterShortuid);
            if ($resolved !== null) {
                $clusterShortuid = $resolved;
            }
        }

        if ($request->has('default_mode')) {
            $routeprofile->default_mode = ScheduleModes::normalize($request->input('default_mode'), 'open');
        }
        if ($request->has('name')) {
            $routeprofile->name = trim((string) $request->input('name', ''));
        }

        try {
            DB::transaction(function () use ($routeprofile, $request, $clusterShortuid) {
                if ($routeprofile->isDirty()) {
                    $id = $routeprofile->id;
                    if ($id === null || $id === '') {
                        throw new \RuntimeException('Route profile id is missing');
                    }
                    RouteProfile::where('id', $id)->update($routeprofile->getDirty());
                    $routeprofile->syncOriginal();
                }
                if ($request->has('lines')) {
                    $this->replaceLines($routeprofile, $clusterShortuid, $request->input('lines', []));
                }
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($this->withLines($routeprofile->fresh()), 200);
    }

    public function delete(RouteProfile $routeprofile)
    {
        $this->assertModelClusterAllowed($routeprofile);
        try {
            DB::transaction(function () use ($routeprofile) {
                RouteProfileLine::where('profile', $routeprofile->shortuid)->delete();
                $routeprofile->delete();
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json(null, 204);
    }

    private function withLines(RouteProfile $profile): array
    {
        $arr = $profile->toArray();
        $lines = RouteProfileLine::where('profile', $profile->shortuid)
            ->orderBy('mode')
            ->get(['mode', 'destination', 'shortuid', 'id']);
        $arr['lines'] = $lines->map(fn ($l) => [
            'mode' => $l->mode,
            'destination' => $l->destination,
            'shortuid' => $l->shortuid,
            'id' => $l->id,
        ])->values()->all();

        return $arr;
    }

    private function validateLines($validator, $lines): void
    {
        if (! is_array($lines)) {
            return;
        }
        $seen = [];
        foreach ($lines as $i => $line) {
            if (! is_array($line)) {
                $validator->errors()->add("lines.$i", 'Each line must be an object.');
                continue;
            }
            $mode = ScheduleModes::normalize($line['mode'] ?? null, '');
            if ($mode === '' || ! ScheduleModes::isValid($mode)) {
                $validator->errors()->add("lines.$i.mode", 'Invalid or missing mode.');
                continue;
            }
            if (isset($seen[$mode])) {
                $validator->errors()->add("lines.$i.mode", 'Duplicate mode in profile lines.');
            }
            $seen[$mode] = true;
            $dest = trim((string) ($line['destination'] ?? ''));
            if ($dest === '') {
                $validator->errors()->add("lines.$i.destination", 'Destination is required.');
            }
        }
    }

    /**
     * Replace all lines for a profile (atomic via caller transaction).
     *
     * @param  list<array{mode?: string, destination?: string}>  $lines
     */
    private function replaceLines(RouteProfile $profile, string $clusterShortuid, $lines): void
    {
        RouteProfileLine::where('profile', $profile->shortuid)->delete();
        if (! is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $mode = ScheduleModes::normalize($line['mode'] ?? null, '');
            $dest = trim((string) ($line['destination'] ?? ''));
            if ($mode === '' || $dest === '') {
                continue;
            }
            $row = new RouteProfileLine;
            $row->id = generate_ksuid();
            $row->shortuid = generate_shortuid();
            $row->profile = $profile->shortuid;
            $row->cluster = $clusterShortuid;
            $row->mode = $mode;
            $row->destination = $dest;
            $row->save();
        }
    }
}
