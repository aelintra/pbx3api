<?php

namespace App\Http\Controllers;

use App\Services\SchemaService;
use Illuminate\Http\JsonResponse;

/**
 * Exposes schema metadata for admin panels: read_only, updateable, defaults.
 * GET /schemas returns one object per resource (extensions, queues, etc.).
 *
 * @see pbx3spa/workingdocs/FIELD_MUTABILITY_API_PLAN.md
 */
class SchemaController extends Controller
{
    public function index(SchemaService $schemaService): JsonResponse
    {
        return response()->json($schemaService->getSchemas());
    }
}
