<?php

namespace App\Services\Recordings;

/**
 * Parse MixMonitor wav filenames into structured metadata.
 */
class RecordingFilenameParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $tenant, string $filename): array
    {
        $base = preg_replace('/\.wav$/i', '', $filename);

        $isQueueUnmatched = false;
        if (is_string($base) && stripos($base, 'Qexec') === 0) {
            $isQueueUnmatched = true;
            $base = substr($base, strlen('Qexec'));
        }

        $tokens = explode('-', (string) $base);

        $epoch = 0;
        if (isset($tokens[0]) && preg_match('/^(\d+)/', $tokens[0], $m) === 1) {
            $epoch = (int) $m[1];
        }

        $dnid = null;
        $callerid = null;
        $queue = null;
        $extension = null;

        $count = count($tokens);
        if ($count >= 5) {
            $queue = $tokens[2];
            $extension = $tokens[3];
            $callerid = $tokens[4];
        } elseif ($count === 4) {
            $dnid = $tokens[2];
            $callerid = $tokens[3];
        } elseif ($count === 3) {
            $dnid = $tokens[2];
        }

        return [
            'tenant' => $tenant,
            'filename' => $filename,
            'epoch' => $epoch,
            'created_at' => $epoch > 0 ? gmdate('Y-m-d\TH:i:s\Z', $epoch) : null,
            'dnid' => $dnid,
            'callerid' => $callerid,
            'queue' => $queue,
            'extension' => $extension,
            'is_queue' => $queue !== null || $isQueueUnmatched,
        ];
    }
}
