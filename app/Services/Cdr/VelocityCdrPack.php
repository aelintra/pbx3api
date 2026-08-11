<?php

namespace App\Services\Cdr;

/**
 * Velocity CDR test pack — fixture decks vs VelocityCdrQuery expectations.
 *
 * Run via artisan `pbx3:cdr-velocity-pack` or unit tests. Spec:
 * FLEET_TOLL_FRAUD_VELOCITY_REQUIREMENTS.md § CDR pack.
 */
final class VelocityCdrPack
{
    public function __construct(
        private readonly CdrFixtureService $fixture,
        private readonly VelocityCdrQuery $query,
    ) {
    }

    /**
     * @return array{
     *   passed: int,
     *   failed: int,
     *   cases: list<array{id: string, ok: bool, detail: string}>
     * }
     */
    public function run(): array
    {
        $cases = [];
        $cases[] = $this->caseIrsfBurstCounts();
        $cases[] = $this->caseUnderThresholdStillVisible();
        $cases[] = $this->caseInternalNoiseExcluded();
        $cases[] = $this->caseMixedKeepsOnlyPremium();
        $cases[] = $this->caseFailedScanCountsTowardSurge();
        $cases[] = $this->caseThresholdGate();

        $passed = 0;
        $failed = 0;
        foreach ($cases as $c) {
            if ($c['ok']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'cases' => $cases,
        ];
    }

    /** @return array{id: string, ok: bool, detail: string} */
    private function caseIrsfBurstCounts(): array
    {
        $id = 'irsf_burst_counts';
        $path = $this->tempDb();
        try {
            $this->pointAt($path);
            $this->fixture->seed([
                'path' => $path,
                'deck' => 'irsf',
                'count' => 12,
                'src' => '1001',
                'force' => true,
            ]);
            $r = $this->query->candidates(5, [CdrFixtureService::LAB_PREMIUM_PREFIX]);
            $ok = $r['available']
                && $r['total'] === 12
                && ($r['by_src']['1001'] ?? 0) === 12;
            $detail = sprintf('total=%d by_src[1001]=%d', $r['total'], $r['by_src']['1001'] ?? 0);

            return ['id' => $id, 'ok' => $ok, 'detail' => $detail];
        } finally {
            @unlink($path);
        }
    }

    /** Under N — rows still queryable (scanner must not fire; pack only checks query). */
    private function caseUnderThresholdStillVisible(): array
    {
        $id = 'under_threshold_visible';
        $path = $this->tempDb();
        try {
            $this->pointAt($path);
            $this->fixture->seed([
                'path' => $path,
                'deck' => 'irsf',
                'count' => 3,
                'src' => '1001',
                'force' => true,
            ]);
            $r = $this->query->candidates(5, [CdrFixtureService::LAB_PREMIUM_PREFIX]);
            $n = (int) config('pbx3_ops.velocity_threshold', 10);
            $ok = $r['total'] === 3 && $r['total'] < $n;
            $detail = sprintf('total=%d threshold_N=%d (expect total < N)', $r['total'], $n);

            return ['id' => $id, 'ok' => $ok, 'detail' => $detail];
        } finally {
            @unlink($path);
        }
    }

    private function caseInternalNoiseExcluded(): array
    {
        $id = 'internal_noise_excluded';
        $path = $this->tempDb();
        try {
            $this->pointAt($path);
            $this->fixture->seed([
                'path' => $path,
                'deck' => 'internal-noise',
                'count' => 8,
                'src' => '1001',
                'force' => true,
            ]);
            $r = $this->query->candidates(5, [CdrFixtureService::LAB_PREMIUM_PREFIX]);
            $ok = $r['total'] === 0;
            $detail = sprintf('total=%d (expect 0)', $r['total']);

            return ['id' => $id, 'ok' => $ok, 'detail' => $detail];
        } finally {
            @unlink($path);
        }
    }

    private function caseMixedKeepsOnlyPremium(): array
    {
        $id = 'mixed_premium_only';
        $path = $this->tempDb();
        try {
            $this->pointAt($path);
            $this->fixture->seed([
                'path' => $path,
                'deck' => 'mixed',
                'count' => 10,
                'src' => '1001',
                'force' => true,
            ]);
            $r = $this->query->candidates(5, [CdrFixtureService::LAB_PREMIUM_PREFIX]);
            $ok = $r['total'] >= 5 && $r['total'] < 10;
            foreach ($r['rows'] as $row) {
                if (! str_starts_with((string) ($row['dst'] ?? ''), CdrFixtureService::LAB_PREMIUM_PREFIX)) {
                    $ok = false;
                    break;
                }
            }
            $detail = sprintf('total=%d (expect 5–9, all premium dst)', $r['total']);

            return ['id' => $id, 'ok' => $ok, 'detail' => $detail];
        } finally {
            @unlink($path);
        }
    }

    /**
     * Failed / short attempts to premium still count — IRSF surge is attempt-volume,
     * not answered-only (V2 query contract).
     */
    private function caseFailedScanCountsTowardSurge(): array
    {
        $id = 'failed_scan_counts';
        $path = $this->tempDb();
        try {
            $this->pointAt($path);
            $this->fixture->seed([
                'path' => $path,
                'deck' => 'failed-scan',
                'count' => 12,
                'src' => '1001',
                'force' => true,
            ]);
            $r = $this->query->candidates(5, [CdrFixtureService::LAB_PREMIUM_PREFIX]);
            $ok = $r['total'] === 12 && ($r['by_src']['1001'] ?? 0) === 12;
            $detail = sprintf('total=%d (failed/busy premium still counted)', $r['total']);

            return ['id' => $id, 'ok' => $ok, 'detail' => $detail];
        } finally {
            @unlink($path);
        }
    }

    /** Same DB: count ≥ N vs under N for scanner gate documentation. */
    private function caseThresholdGate(): array
    {
        $id = 'threshold_gate';
        $path = $this->tempDb();
        try {
            $this->pointAt($path);
            $n = 10;
            $this->fixture->seed([
                'path' => $path,
                'deck' => 'irsf',
                'count' => $n,
                'src' => '2002',
                'force' => true,
            ]);
            $r = $this->query->candidates(5, [CdrFixtureService::LAB_PREMIUM_PREFIX]);
            $count = (int) ($r['by_src']['2002'] ?? 0);
            $fires = $count >= $n;
            $ok = $fires && $count === $n;
            $detail = sprintf('src=2002 count=%d N=%d fires=%s', $count, $n, $fires ? 'yes' : 'no');

            return ['id' => $id, 'ok' => $ok, 'detail' => $detail];
        } finally {
            @unlink($path);
        }
    }

    private function tempDb(): string
    {
        return sys_get_temp_dir().'/pbx3-vel-pack-'.bin2hex(random_bytes(4)).'.db';
    }

    private function pointAt(string $path): void
    {
        config([
            'pbx3_cdr.sqlite_path' => $path,
            'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
            'pbx3_ops.velocity_window_minutes' => 5,
            'pbx3_ops.velocity_threshold' => 10,
        ]);
    }
}
