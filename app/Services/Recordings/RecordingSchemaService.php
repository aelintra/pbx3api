<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure the recordings catalog table exists on the instance DB.
 */
class RecordingSchemaService
{
    public function tableExists(): bool
    {
        try {
            return Schema::hasTable('recordings');
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureTable(): bool
    {
        if ($this->tableExists()) {
            return true;
        }

        $path = (string) config('pbx3_recordings.schema_sql');
        if (! is_file($path)) {
            Log::warning('recordings schema SQL not found', ['path' => $path]);

            return false;
        }

        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            Log::warning('recordings schema SQL unreadable', ['path' => $path]);

            return false;
        }

        try {
            DB::unprepared($sql);
        } catch (\Throwable $e) {
            Log::error('recordings schema migration failed', ['error' => $e->getMessage()]);

            return false;
        }

        return $this->tableExists();
    }
}
