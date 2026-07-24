<?php

namespace App\Services\Ops;

use App\Models\Extension;
use Illuminate\Support\Facades\Log;

/**
 * Velocity V5 act: active=NO + clear CF/Follow-me + hangup + genAst/reload.
 *
 * Prefer AMI hangup of that endpoint's channels (option A). Asterisk CLI used so
 * artisan scanners are not killed by Ami Response::make()->send() paths.
 */
final class VelocityPhoneActuator
{
    public function __construct(
        private readonly VelocityPhoneAttributor $attributor,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *   attempted: bool,
     *   applied: bool,
     *   skipped_reason: string,
     *   attribution_reason: string,
     *   extension_pkey: string,
     *   extension_shortuid: string,
     *   active_set: bool,
     *   forwards_cleared: bool,
     *   hung_up: list<string>,
     *   genast: bool,
     *   errors: list<string>
     * }
     */
    public function actOnSurge(string $src, array $rows, string $accountcode = ''): array
    {
        $out = [
            'attempted' => false,
            'applied' => false,
            'skipped_reason' => '',
            'attribution_reason' => '',
            'extension_pkey' => '',
            'extension_shortuid' => '',
            'active_set' => false,
            'forwards_cleared' => false,
            'hung_up' => [],
            'genast' => false,
            'errors' => [],
        ];

        if (! filter_var(config('pbx3_ops.velocity_act_enabled', false), FILTER_VALIDATE_BOOL)) {
            $out['skipped_reason'] = 'act_disabled';

            return $out;
        }

        $attr = $this->attributor->attribute($src, $rows, $accountcode);
        $out['attribution_reason'] = $attr['reason'];
        $phone = $attr['phone'];
        if ($phone === null) {
            $out['skipped_reason'] = 'uncertain_attribution';

            return $out;
        }

        $pkey = trim((string) ($phone->pkey ?? ''));
        $uid = trim((string) ($phone->shortuid ?? ''));
        $out['extension_pkey'] = $pkey;
        $out['extension_shortuid'] = $uid;
        $out['attempted'] = true;

        if ($this->isAllowlisted($pkey, $uid)) {
            $out['skipped_reason'] = 'allowlisted';

            return $out;
        }

        try {
            $phone->active = 'NO';
            $phone->celltwin = 'OFF';
            $phone->callbackto = 'desk';
            $phone->cellphone = null;
            $phone->z_updater = 'velocity';
            $phone->save();
            $out['active_set'] = true;
        } catch (\Throwable $e) {
            $out['errors'][] = 'db: '.$e->getMessage();
            Log::warning('velocity act db failed', ['pkey' => $pkey, 'error' => $e->getMessage()]);

            return $out;
        }

        $skipAst = filter_var(config('pbx3_ops.velocity_skip_asterisk', false), FILTER_VALIDATE_BOOL);
        if (! $skipAst) {
            $keys = array_values(array_unique(array_filter([$uid, $pkey])));
            $out['forwards_cleared'] = $this->clearAstDbForwards($keys);
            $out['hung_up'] = $this->hangupEndpointChannels($uid !== '' ? $uid : $pkey);
            $out['genast'] = $this->runGenAstReload();
        } else {
            $out['forwards_cleared'] = true;
            $out['genast'] = true;
        }

        $out['applied'] = $out['active_set'] && $out['errors'] === [];

        return $out;
    }

    private function isAllowlisted(string $pkey, string $uid): bool
    {
        $raw = (string) config('pbx3_ops.velocity_allowlist', '');
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (strcasecmp($part, $pkey) === 0 || strcasecmp($part, $uid) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keys
     */
    private function clearAstDbForwards(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            foreach (['cfim', 'cfbs', 'ringdelay'] as $family) {
                if (! $this->asteriskRx('database del '.$family.' '.$key)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    /**
     * @return list<string> hung-up channel names
     */
    private function hangupEndpointChannels(string $endpoint): array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return [];
        }

        $raw = $this->asteriskRxCapture('core show channels concise');
        if ($raw === null) {
            return [];
        }

        $hung = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // concise: Channel!…  — also accept plain Channel: lines
            $channel = $line;
            if (str_contains($line, '!')) {
                $channel = explode('!', $line, 2)[0];
            } elseif (preg_match('/^Channel:\s*(.+)$/i', $line, $m) === 1) {
                $channel = trim($m[1]);
            }
            if (preg_match('/^(?:PJSIP|SIP)\/'.preg_quote($endpoint, '/').'-/i', $channel) !== 1) {
                continue;
            }
            if ($this->asteriskRx('channel request hangup '.$channel)) {
                $hung[] = $channel;
            }
        }

        return $hung;
    }

    private function runGenAstReload(): bool
    {
        $genAst = (string) env('PBX3_GENAST_SCRIPT', '/opt/pbx3/scripts/genAst.sh');
        $asterisk = (string) env('PBX3_ASTERISK_EXEC', '/usr/sbin/asterisk');

        if (is_readable($genAst)) {
            $out = [];
            $code = 0;
            exec('/bin/sh '.escapeshellarg($genAst).' 2>&1', $out, $code);
            if ($code !== 0) {
                Log::warning('velocity genAst failed', ['code' => $code, 'out' => implode("\n", $out)]);

                return false;
            }
        }

        $relout = [];
        $relcode = 0;
        exec(escapeshellarg($asterisk).' -rx '.escapeshellarg('core reload').' 2>&1', $relout, $relcode);
        if ($relcode !== 0) {
            Log::warning('velocity core reload failed', ['code' => $relcode, 'out' => implode("\n", $relout)]);

            return false;
        }

        return true;
    }

    private function asteriskRx(string $command): bool
    {
        $asterisk = (string) env('PBX3_ASTERISK_EXEC', '/usr/sbin/asterisk');
        $out = [];
        $code = 0;
        exec(escapeshellarg($asterisk).' -rx '.escapeshellarg($command).' 2>&1', $out, $code);

        return $code === 0;
    }

    private function asteriskRxCapture(string $command): ?string
    {
        $asterisk = (string) env('PBX3_ASTERISK_EXEC', '/usr/sbin/asterisk');
        $out = [];
        $code = 0;
        exec(escapeshellarg($asterisk).' -rx '.escapeshellarg($command).' 2>&1', $out, $code);
        if ($code !== 0) {
            return null;
        }

        return implode("\n", $out);
    }
}
