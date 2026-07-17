<?php

namespace App\Services\Ops;

use App\Models\IpPhone;

/**
 * Map Asterisk REGISTER auth id (ipphone.shortuid) to dialable extension + name.
 */
final class EndpointUidResolver
{
    /**
     * @return array{extension: string, endpoint_uid: string, endpoint_name: string}
     */
    public static function resolve(string $authId): array
    {
        $authId = trim($authId);
        $out = [
            'extension' => $authId !== '' ? $authId : '(unknown)',
            'endpoint_uid' => $authId,
            'endpoint_name' => '',
        ];
        if ($authId === '' || $authId === '(unknown)') {
            return $out;
        }

        try {
            $row = IpPhone::query()
                ->whereRaw('LOWER(shortuid) = ?', [strtolower($authId)])
                ->first(['pkey', 'shortuid', 'desc']);
            if ($row === null) {
                // Already dialable / legacy AccountID = pkey
                $row = IpPhone::query()
                    ->where('pkey', $authId)
                    ->first(['pkey', 'shortuid', 'desc']);
            }
            if ($row === null) {
                return $out;
            }
            $pkey = trim((string) ($row->pkey ?? ''));
            $uid = trim((string) ($row->shortuid ?? ''));
            $name = trim((string) ($row->desc ?? ''));

            return [
                'extension' => $pkey !== '' ? $pkey : $authId,
                'endpoint_uid' => $uid !== '' ? $uid : $authId,
                'endpoint_name' => $name,
            ];
        } catch (\Throwable $e) {
            // Avoid Log facade here so plain PHPUnit can exercise the fallback path.
            error_log('[endpoint-uid-resolve] '.$authId.': '.$e->getMessage());

            return $out;
        }
    }
}
