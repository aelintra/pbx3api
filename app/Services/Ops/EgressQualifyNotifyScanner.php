<?php

namespace App\Services\Ops;

use App\Models\Sysglobal;
use App\Services\Fleet\FleetPostureService;
use Illuminate\Support\Facades\Log;

/**
 * Poll Egress PJSIP qualify; emit Gatekeeper ops-events on Avail↔Unavail transitions.
 */
final class EgressQualifyNotifyScanner
{
    public function __construct(
        private readonly GatekeeperOpsClient $client,
        private readonly FleetPostureService $posture,
    ) {
    }

    /**
     * @return array{
     *   checked:bool,
     *   qualify:string,
     *   transition:?string,
     *   emitted:bool,
     *   seeded:bool,
     *   errors:list<string>
     * }
     */
    public function run(): array
    {
        $out = [
            'checked' => false,
            'qualify' => 'Unknown',
            'transition' => null,
            'emitted' => false,
            'seeded' => false,
            'errors' => [],
        ];

        if (! filter_var(config('pbx3_ops.egress_unavail_notify_enabled', false), FILTER_VALIDATE_BOOL)) {
            return $out;
        }
        if (! $this->client->isConfigured()) {
            $out['errors'][] = 'gatekeeper not configured';

            return $out;
        }
        if (! $this->posture->isFleetNode()) {
            return $out;
        }

        $globals = Sysglobal::query()->first();
        $instanceId = trim((string) ($globals?->id ?? ''));
        $fqdn = trim((string) ($globals?->fqdn ?? ''));
        $label = $fqdn !== '' ? explode('.', $fqdn, 2)[0] : $instanceId;
        if ($instanceId === '') {
            $out['errors'][] = 'globals.id empty';

            return $out;
        }

        $live = $this->posture->egressQualifyLive();
        $qualify = (string) ($live['state'] ?? 'Unknown');
        $out['qualify'] = $qualify;
        $out['checked'] = true;

        $statePath = (string) config('pbx3_ops.egress_state_path', storage_path('app/ops-egress-qualify.json'));
        $state = $this->loadState($statePath);
        $threshold = max(1, (int) config('pbx3_ops.egress_miss_threshold', 2));
        $now = time();

        // First run: seed without mail (match Fail2ban ban notify).
        if (! ($state['seeded'] ?? false)) {
            $state = [
                'seeded' => true,
                'reported' => $qualify === 'Unavail' ? 'Unavail' : ($qualify === 'Avail' ? 'Avail' : 'Unknown'),
                'consecutive_unavail' => $qualify === 'Unavail' ? $threshold : 0,
                'last_ok_at' => $qualify === 'Avail' ? $now : ($state['last_ok_at'] ?? null),
                'last_rtt_ms' => $live['rtt_ms'] ?? null,
                'updated_at' => $now,
            ];
            $this->saveState($statePath, $state);
            $out['seeded'] = true;

            return $out;
        }

        $reported = (string) ($state['reported'] ?? 'Unknown');
        $consecutive = (int) ($state['consecutive_unavail'] ?? 0);

        if ($qualify === 'Unknown') {
            // AMI glitch — do not change consecutive / reported.
            $state['updated_at'] = $now;
            $this->saveState($statePath, $state);

            return $out;
        }

        if ($qualify === 'Unavail') {
            $consecutive++;
        } else {
            $consecutive = 0;
            $state['last_ok_at'] = $now;
            $state['last_rtt_ms'] = $live['rtt_ms'] ?? null;
        }

        $state['consecutive_unavail'] = $consecutive;
        $state['updated_at'] = $now;

        $transition = null;
        if ($qualify === 'Unavail' && $consecutive >= $threshold && $reported !== 'Unavail') {
            $transition = 'down';
        } elseif ($qualify === 'Avail' && $reported === 'Unavail') {
            $transition = 'cleared';
        }

        if ($transition === null) {
            $this->saveState($statePath, $state);

            return $out;
        }

        $trunk = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');
        $event = [
            'type' => 'egress_unavail',
            'transition' => $transition,
            'instance_id' => $instanceId,
            'instance_label' => $label,
            'fqdn' => $fqdn,
            'state' => $qualify,
            'rtt_ms' => $live['rtt_ms'] ?? null,
            'consecutive_unavail' => $consecutive,
            'egress_trunk' => $trunk,
            'latency' => $live['latency'] ?? null,
        ];

        try {
            $this->client->postEvent($event);
            $out['emitted'] = true;
            $out['transition'] = $transition;
            $state['reported'] = $qualify === 'Unavail' ? 'Unavail' : 'Avail';
            $state['last_notified_at'] = $now;
            $state['last_transition'] = $transition;
        } catch (\Throwable $e) {
            $out['errors'][] = $e->getMessage();
            Log::warning('egress qualify notify emit failed', [
                'transition' => $transition,
                'error' => $e->getMessage(),
            ]);
        }

        $this->saveState($statePath, $state);

        return $out;
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
