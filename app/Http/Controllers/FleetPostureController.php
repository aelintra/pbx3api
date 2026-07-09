<?php

namespace App\Http\Controllers;

use App\Services\Fleet\FleetPostureService;
use Illuminate\Http\JsonResponse;

class FleetPostureController extends Controller
{
    public function show(FleetPostureService $posture): JsonResponse
    {
        return response()->json($posture->toArray());
    }
}
