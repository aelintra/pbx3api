<?php

namespace App\Services\Ops;

use App\Models\Extension;
use Illuminate\Support\Facades\Log;

/**
 * Map CDR src/channel evidence → exactly one ipphone row (velocity V5).
 *
 * Fail-safe: uncertain → null (notify-only; never deactivate the wrong phone).
 * Spec: FLEET_TOLL_FRAUD_VELOCITY_REQUIREMENTS.md § V5 attribution.
 */
final class VelocityPhoneAttributor
{
    /**
     * @param  list<array<string, mixed>>  $rows  candidate CDR rows for this src
     * @return array{
     *   phone: ?Extension,
     *   reason: string,
     *   endpoint_hint: string,
     *   candidates: int
     * }
     */
    public function attribute(string $src, array $rows, string $accountcode = ''): array
    {
        $src = trim($src);
        $accountcode = trim($accountcode);
        $empty = [
            'phone' => null,
            'reason' => 'empty_src',
            'endpoint_hint' => '',
            'candidates' => 0,
        ];
        if ($src === '' || $src === '(unknown)') {
            return $empty;
        }

        $endpointHints = $this->endpointHintsFromChannels($rows);
        $hint = count($endpointHints) === 1 ? array_key_first($endpointHints) : '';

        try {
            $query = Extension::query();
            if ($accountcode !== '') {
                $query->where('cluster', $accountcode);
            }

            $matches = collect();

            if ($hint !== '') {
                $byUid = (clone $query)
                    ->whereRaw('LOWER(shortuid) = ?', [strtolower($hint)])
                    ->get();
                if ($byUid->count() === 1) {
                    return $this->ok($byUid->first(), 'channel_shortuid', $hint, 1);
                }
                if ($byUid->count() > 1) {
                    return $this->fail('ambiguous_channel_shortuid', $hint, $byUid->count());
                }
            }

            // Prefer dialable pkey == CDR src (typical Asterisk CDR(src)).
            $byPkey = (clone $query)->where('pkey', $src)->get();
            if ($byPkey->count() === 1) {
                return $this->ok($byPkey->first(), 'src_pkey', $hint, 1);
            }
            if ($byPkey->count() > 1) {
                return $this->fail('ambiguous_src_pkey', $hint, $byPkey->count());
            }

            $byUidSrc = (clone $query)
                ->whereRaw('LOWER(shortuid) = ?', [strtolower($src)])
                ->get();
            if ($byUidSrc->count() === 1) {
                return $this->ok($byUidSrc->first(), 'src_shortuid', $hint !== '' ? $hint : $src, 1);
            }
            if ($byUidSrc->count() > 1) {
                return $this->fail('ambiguous_src_shortuid', $hint, $byUidSrc->count());
            }

            // Multiple channel endpoints in the burst → refuse.
            if (count($endpointHints) > 1) {
                return $this->fail('mixed_channel_endpoints', implode(',', array_keys($endpointHints)), count($endpointHints));
            }

            return $this->fail('no_phone', $hint, 0);
        } catch (\Throwable $e) {
            Log::warning('velocity attribution failed', ['src' => $src, 'error' => $e->getMessage()]);

            return $this->fail('lookup_error', $hint, 0);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, true>
     */
    public function endpointHintsFromChannels(array $rows): array
    {
        $hints = [];
        foreach ($rows as $row) {
            $channel = trim((string) ($row['channel'] ?? ''));
            if ($channel === '') {
                continue;
            }
            // PJSIP/shortuid-00000001 or SIP/1001-…
            if (preg_match('/^(?:PJSIP|SIP)\/([^\/\-]+)-/i', $channel, $m) === 1) {
                $hints[$m[1]] = true;
            }
        }

        return $hints;
    }

    /** @return array{phone: Extension, reason: string, endpoint_hint: string, candidates: int} */
    private function ok(Extension $phone, string $reason, string $hint, int $candidates): array
    {
        return [
            'phone' => $phone,
            'reason' => $reason,
            'endpoint_hint' => $hint,
            'candidates' => $candidates,
        ];
    }

    /** @return array{phone: null, reason: string, endpoint_hint: string, candidates: int} */
    private function fail(string $reason, string $hint, int $candidates): array
    {
        return [
            'phone' => null,
            'reason' => $reason,
            'endpoint_hint' => $hint,
            'candidates' => $candidates,
        ];
    }
}
