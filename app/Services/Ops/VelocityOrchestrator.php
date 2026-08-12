<?php

namespace App\Services\Ops;

/**
 * Velocity WP1 — run enabled detectors (IRSF + optional off-hours).
 *
 * Spec: FLEET_TOLL_FRAUD_VELOCITY_IMPLEMENTATION_PLAN.md WP1.
 */
final class VelocityOrchestrator
{
    public function __construct(
        private readonly VelocityPolicyResolver $policyResolver,
        private readonly VelocityIrsfScanner $irsf,
        private readonly VelocityOffHoursScanner $offHours,
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
     *   policy_source:string,
     *   detectors:array{irsf:array<string,mixed>, off_hours:?array<string,mixed>},
     *   errors:list<string>
     * }
     */
    public function run(): array
    {
        $policyApply = $this->policyResolver->apply();
        // IRSF scanner also applies policy (solo path / tests); second apply is cheap.

        $irsf = $this->irsf->run();
        $off = null;
        $offHoursOn = filter_var(config('pbx3_ops.velocity_off_hours_enabled', false), FILTER_VALIDATE_BOOL);
        if ($offHoursOn && filter_var(config('pbx3_ops.velocity_enabled', false), FILTER_VALIDATE_BOOL)) {
            $off = $this->offHours->run();
        }

        $merge = static function (array $a, ?array $b): array {
            if ($b === null) {
                return $a;
            }

            return [
                'scanned' => ($a['scanned'] ?? false) || ($b['scanned'] ?? false),
                'candidates' => (int) ($a['candidates'] ?? 0) + (int) ($b['candidates'] ?? 0),
                'over_threshold' => (int) ($a['over_threshold'] ?? 0) + (int) ($b['over_threshold'] ?? 0),
                'emitted' => (int) ($a['emitted'] ?? 0) + (int) ($b['emitted'] ?? 0),
                'cleared' => (int) ($a['cleared'] ?? 0) + (int) ($b['cleared'] ?? 0),
                'skipped_hysteresis' => (int) ($a['skipped_hysteresis'] ?? 0) + (int) ($b['skipped_hysteresis'] ?? 0),
                'acted' => (int) ($a['acted'] ?? 0) + (int) ($b['acted'] ?? 0),
                'errors' => array_values(array_merge(
                    is_array($a['errors'] ?? null) ? $a['errors'] : [],
                    is_array($b['errors'] ?? null) ? $b['errors'] : []
                )),
            ];
        };

        $totals = $merge($irsf, $off);

        return [
            'scanned' => $totals['scanned'],
            'candidates' => $totals['candidates'],
            'over_threshold' => $totals['over_threshold'],
            'emitted' => $totals['emitted'],
            'cleared' => $totals['cleared'],
            'skipped_hysteresis' => $totals['skipped_hysteresis'],
            'acted' => $totals['acted'],
            'policy_source' => $policyApply['source'] !== 'local'
                ? $policyApply['source']
                : ($irsf['policy_source'] ?? 'env'),
            'detectors' => [
                'irsf' => $irsf,
                'off_hours' => $off,
            ],
            'errors' => $totals['errors'],
        ];
    }
}
