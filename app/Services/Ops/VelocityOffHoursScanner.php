<?php

namespace App\Services\Ops;

use App\Models\Sysglobal;
use App\Services\Cdr\VelocityCdrQuery;
use Illuminate\Support\Facades\Log;

/**
 * Velocity WP1 — high-risk outbound surge inside off-hours / weekend windows.
 *
 * Same N/T/Q + V5 act as IRSF; separate hysteresis + Gatekeeper type velocity_off_hours.
 * Spec: FLEET_TOLL_FRAUD_VELOCITY_IMPLEMENTATION_PLAN.md WP1.
 */
final class VelocityOffHoursScanner
{
    public function __construct(
        private readonly GatekeeperOpsClient $client,
        private readonly VelocityCdrQuery $query,
        private readonly VelocityPhoneActuator $actuator,
        private readonly VelocityOffHoursClock $clock,
    ) {
    }

    /**
     * @return array{
     *   scanned:bool,
     *   candidates:int,
     *   over_threshold:int,
     *   emitted:int,
     *   cleared:int,
     *   skipped_hysteresis:int,
     *   acted:int,
     *   errors:list<string>
     * }
     */
    public function run(): array
    {
        $out = [
            'scanned' => false,
            'candidates' => 0,
            'over_threshold' => 0,
            'emitted' => 0,
            'cleared' => 0,
            'skipped_hysteresis' => 0,
            'acted' => 0,
            'errors' => [],
        ];

        if (! filter_var(config('pbx3_ops.velocity_enabled', false), FILTER_VALIDATE_BOOL)) {
            return $out;
        }
        if (! filter_var(config('pbx3_ops.velocity_off_hours_enabled', false), FILTER_VALIDATE_BOOL)) {
            return $out;
        }
        if (! $this->client->isConfigured()) {
            $out['errors'][] = 'gatekeeper not configured';

            return $out;
        }

        [$instanceId, $label, $fqdn] = $this->resolveInstance();
        if ($instanceId === '') {
            $out['errors'][] = 'globals.id empty';

            return $out;
        }

        $n = max(1, (int) config('pbx3_ops.velocity_off_hours_threshold', 20));
        $t = max(1, (int) config('pbx3_ops.velocity_off_hours_window_minutes', 60));
        $q = max(1, (int) config('pbx3_ops.velocity_quiet_minutes', 30));
        $tz = trim((string) config('pbx3_ops.velocity_off_hours_tz', 'UTC'));
        if ($tz === '') {
            $tz = 'UTC';
        }
        /** @var list<array<string, mixed>> $windows */
        $windows = config('pbx3_ops.velocity_off_hours_windows', []);
        if (! is_array($windows) || $windows === []) {
            $windows = [
                ['dow' => [6, 0], 'start' => '18:00', 'end' => '06:00'],
            ];
        }
        $now = time();

        $result = $this->query->candidates($t);
        $out['scanned'] = true;

        if (! $result['available']) {
            $out['errors'][] = 'CDR SQLite not available at '.$result['path'];

            return $out;
        }

        $filteredRows = [];
        foreach ($result['rows'] as $row) {
            $cd = $this->clock->parseCalldate((string) ($row['calldate'] ?? ''), $tz);
            if ($cd === null) {
                continue;
            }
            if ($this->clock->isInWindow($cd, $tz, $windows)) {
                $filteredRows[] = $row;
            }
        }

        $bySrc = [];
        foreach ($filteredRows as $row) {
            $src = trim((string) ($row['src'] ?? ''));
            if ($src === '') {
                $src = '(unknown)';
            }
            $bySrc[$src] = ($bySrc[$src] ?? 0) + 1;
        }
        $out['candidates'] = count($filteredRows);
        $rowsBySrc = $this->groupRowsBySrc($filteredRows);

        $statePath = (string) config('pbx3_ops.velocity_state_path', storage_path('app/ops-velocity.json'));
        $state = $this->loadState($statePath);
        /** @var array<string, array{active:bool, under_since:?int, last_emitted_at:?int, last_count:int}> $alerts */
        $alerts = is_array($state['alerts_off_hours'] ?? null) ? $state['alerts_off_hours'] : [];

        $srcs = array_unique(array_merge(array_keys($bySrc), array_keys($alerts)));

        foreach ($srcs as $src) {
            $count = (int) ($bySrc[$src] ?? 0);
            $alert = $alerts[$src] ?? [
                'active' => false,
                'under_since' => null,
                'last_emitted_at' => null,
                'last_count' => 0,
            ];

            if ($count >= $n) {
                $out['over_threshold']++;
                $alert['under_since'] = null;
                $alert['last_count'] = $count;

                if ($alert['active'] ?? false) {
                    $out['skipped_hysteresis']++;
                    $alerts[$src] = $alert;

                    continue;
                }

                $rows = $rowsBySrc[$src] ?? [];
                $act = $this->actuator->actOnSurge(
                    $src,
                    $rows,
                    $this->primaryAccountcode($rows)
                );
                if ($act['applied']) {
                    $out['acted']++;
                }
                foreach ($act['errors'] as $err) {
                    $out['errors'][] = $err;
                }

                $payload = $this->buildPayload(
                    $instanceId,
                    $label,
                    $fqdn,
                    $src,
                    $count,
                    $t,
                    $rows,
                    'down',
                    $act
                );

                try {
                    $this->client->postEvent($payload);
                    $out['emitted']++;
                    $alert['active'] = true;
                    $alert['last_emitted_at'] = $now;
                } catch (\Throwable $e) {
                    $out['errors'][] = $e->getMessage();
                    Log::warning('velocity_off_hours emit failed', [
                        'src' => $src,
                        'error' => $e->getMessage(),
                    ]);
                }
                $alerts[$src] = $alert;

                continue;
            }

            $alert['last_count'] = $count;
            if (! ($alert['active'] ?? false)) {
                unset($alerts[$src]);

                continue;
            }

            if ($alert['under_since'] === null) {
                $alert['under_since'] = $now;
                $alerts[$src] = $alert;

                continue;
            }

            $underFor = $now - (int) $alert['under_since'];
            if ($underFor < ($q * 60)) {
                $alerts[$src] = $alert;

                continue;
            }

            $payload = $this->buildPayload(
                $instanceId,
                $label,
                $fqdn,
                $src,
                $count,
                $t,
                $rowsBySrc[$src] ?? [],
                'cleared'
            );

            try {
                $this->client->postEvent($payload);
                $out['cleared']++;
                unset($alerts[$src]);
            } catch (\Throwable $e) {
                $out['errors'][] = $e->getMessage();
                Log::warning('velocity_off_hours cleared emit failed', [
                    'src' => $src,
                    'error' => $e->getMessage(),
                ]);
                $alerts[$src] = $alert;
            }
        }

        $state['alerts_off_hours'] = $alerts;
        $state['updated_at'] = $now;
        $state['off_hours_last_candidates'] = count($filteredRows);
        $state['off_hours_window_minutes'] = $t;
        $state['off_hours_threshold'] = $n;
        $this->saveState($statePath, $state);

        return $out;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function resolveInstance(): array
    {
        $instanceId = trim((string) config('pbx3_ops.velocity_instance_id', ''));
        $fqdn = trim((string) config('pbx3_ops.velocity_instance_fqdn', ''));
        if ($instanceId === '' || $fqdn === '') {
            $globals = Sysglobal::query()->first();
            if ($instanceId === '') {
                $instanceId = trim((string) ($globals?->id ?? ''));
            }
            if ($fqdn === '') {
                $fqdn = trim((string) ($globals?->fqdn ?? ''));
            }
        }
        $label = $fqdn !== '' ? explode('.', $fqdn, 2)[0] : $instanceId;

        return [$instanceId, $label, $fqdn];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupRowsBySrc(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $src = trim((string) ($row['src'] ?? ''));
            if ($src === '') {
                $src = '(unknown)';
            }
            $out[$src][] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $act
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $instanceId,
        string $label,
        string $fqdn,
        string $src,
        int $count,
        int $windowMinutes,
        array $rows,
        string $transition,
        ?array $act = null,
    ): array {
        $accountcodes = [];
        $masked = [];
        $first = null;
        $last = null;
        foreach ($rows as $row) {
            $ac = trim((string) ($row['accountcode'] ?? ''));
            if ($ac !== '') {
                $accountcodes[$ac] = true;
            }
            $dst = trim((string) ($row['dst'] ?? ''));
            if ($dst !== '') {
                $masked[$this->maskDestination($dst)] = true;
            }
            $cd = (string) ($row['calldate'] ?? '');
            if ($cd !== '') {
                if ($first === null || $cd < $first) {
                    $first = $cd;
                }
                if ($last === null || $cd > $last) {
                    $last = $cd;
                }
            }
        }

        $payload = [
            'type' => 'velocity_off_hours',
            'transition' => $transition,
            'rule' => 'off_hours',
            'instance_id' => $instanceId,
            'instance_label' => $label,
            'fqdn' => $fqdn,
            'extension' => $src,
            'accountcode' => count($accountcodes) === 1 ? array_key_first($accountcodes) : '',
            'accountcodes' => array_keys($accountcodes),
            'count' => $count,
            'window_minutes' => $windowMinutes,
            'masked_prefixes' => array_keys($masked),
            'first_calldate' => $first,
            'last_calldate' => $last,
            'auto_block' => false,
            'forwards_cleared' => false,
            'hung_up_count' => 0,
            'act_skipped_reason' => '',
            'attribution_reason' => '',
            'extension_pkey' => '',
            'extension_shortuid' => '',
        ];

        if (is_array($act)) {
            $payload['auto_block'] = (bool) ($act['applied'] ?? false);
            $payload['forwards_cleared'] = (bool) ($act['forwards_cleared'] ?? false);
            $payload['hung_up_count'] = is_array($act['hung_up'] ?? null) ? count($act['hung_up']) : 0;
            $payload['act_skipped_reason'] = (string) ($act['skipped_reason'] ?? '');
            $payload['attribution_reason'] = (string) ($act['attribution_reason'] ?? '');
            $payload['extension_pkey'] = (string) ($act['extension_pkey'] ?? '');
            $payload['extension_shortuid'] = (string) ($act['extension_shortuid'] ?? '');
            if ($payload['extension_pkey'] !== '') {
                $payload['extension'] = $payload['extension_pkey'];
            }
        }

        return $payload;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function primaryAccountcode(array $rows): string
    {
        $codes = [];
        foreach ($rows as $row) {
            $ac = trim((string) ($row['accountcode'] ?? ''));
            if ($ac !== '') {
                $codes[$ac] = true;
            }
        }

        return count($codes) === 1 ? (string) array_key_first($codes) : '';
    }

    public function maskDestination(string $dst): string
    {
        $dst = trim($dst);
        if ($dst === '') {
            return '';
        }
        $keep = min(5, strlen($dst));

        return substr($dst, 0, $keep).'***';
    }

    /** @return array<string, mixed> */
    private function loadState(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(string $path, array $state): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX
        );
    }
}
