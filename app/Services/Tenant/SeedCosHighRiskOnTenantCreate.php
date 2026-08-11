<?php

namespace App\Services\Tenant;

use App\Models\ClassOfService;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Seed high-risk CoS deny rules (prevention). Spec: HIGH_RISK_DIAL_BLOCK_POSTURE.md
 *
 * Patterns: config/cos/highrisk-{uk|us}-starter.dialplan
 * Enable create-path: PBX3_COS_HIGHRISK_SEED=true + PBX3_COS_HIGHRISK_LOCALE=uk|us
 */
final class SeedCosHighRiskOnTenantCreate
{
    public const PKEY_UK070 = 'HR_UK070';

    public const PKEY_OFFSHORE = 'HR_OFFSHORE';

    /**
     * @return list<ClassOfService> created or updated rows
     */
    public function seed(Tenant $tenant, ?string $locale = null, bool $force = false, bool $oride = false): array
    {
        $clusterKey = trim((string) ($tenant->shortuid ?? ''));
        if ($clusterKey === '') {
            $clusterKey = trim((string) ($tenant->pkey ?? ''));
        }
        if ($clusterKey === '') {
            return [];
        }

        $locale = strtolower(trim($locale ?? (string) config('pbx3_ops.cos_highrisk_locale', 'uk')));
        if (! in_array($locale, ['uk', 'us'], true)) {
            $locale = 'uk';
        }

        $sections = $this->loadDialplanSections($locale);
        $created = [];

        if ($locale === 'uk' && ($sections['PERSONAL'] ?? '') !== '') {
            $row = $this->upsertRule(
                $clusterKey,
                self::PKEY_UK070,
                'High risk — UK personal 070/076',
                'Bars UK personal numbering (070) and pagers (076). Not ordinary mobiles 071–075 / 077–079.',
                $sections['PERSONAL'],
                $force,
                $oride
            );
            if ($row !== null) {
                $created[] = $row;
            }
        }

        if (($sections['OFFSHORE'] ?? '') !== '') {
            $cname = $locale === 'us'
                ? 'High risk — offshore / IRSF (+ UK 070 from US)'
                : 'High risk — offshore / IRSF';
            $row = $this->upsertRule(
                $clusterKey,
                self::PKEY_OFFSHORE,
                $cname,
                'Bars high-risk offshore destinations (Caribbean NANP, premium CCs, satellite). Same set as velocity starter; CoS is prevention.',
                $sections['OFFSHORE'],
                $force,
                $oride
            );
            if ($row !== null) {
                $created[] = $row;
            }
        }

        if ($created !== []) {
            set_commit_dirty();
        }

        return $created;
    }

    public function seedEnabledByConfig(): bool
    {
        return filter_var(config('pbx3_ops.cos_highrisk_seed', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{PERSONAL?: string, OFFSHORE?: string}
     */
    public function loadDialplanSections(string $locale): array
    {
        $path = base_path('config/cos/highrisk-'.$locale.'-starter.dialplan');
        if (! is_readable($path)) {
            Log::warning('SeedCosHighRisk: dialplan file missing', ['path' => $path]);

            return [];
        }

        $sections = ['PERSONAL' => [], 'OFFSHORE' => []];
        $current = 'OFFSHORE';
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                if (preg_match('/^#\s*PERSONAL\b/i', $line) === 1) {
                    $current = 'PERSONAL';
                } elseif (preg_match('/^#\s*OFFSHORE\b/i', $line) === 1) {
                    $current = 'OFFSHORE';
                }

                continue;
            }
            $sections[$current][] = $line;
        }

        $out = [];
        foreach ($sections as $name => $parts) {
            $joined = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
            if ($joined !== '') {
                $out[$name] = $joined;
            }
        }

        return $out;
    }

    private function upsertRule(
        string $cluster,
        string $pkey,
        string $cname,
        string $description,
        string $dialplan,
        bool $force,
        bool $oride = false
    ): ?ClassOfService {
        $existing = ClassOfService::query()
            ->where('cluster', $cluster)
            ->where('pkey', $pkey)
            ->first();

        if ($existing !== null) {
            if (! $force && ! $oride) {
                return null;
            }
            // --oride alone still updates force-include flags; --force refreshes patterns too.
            if ($force) {
                $existing->cname = $cname;
                $existing->description = $description;
                $existing->dialplan = $dialplan;
                $existing->active = 'YES';
                $existing->defaultopen = 'YES';
                $existing->defaultclosed = 'YES';
            }
            if ($oride) {
                $existing->orideopen = 'YES';
                $existing->orideclosed = 'YES';
            }
            $existing->z_updater = 'cos-highrisk-seed';
            $existing->save();

            return $existing;
        }

        $row = new ClassOfService;
        $row->id = generate_ksuid();
        $row->shortuid = $this->newShortuid();
        $row->pkey = $pkey;
        $row->cluster = $cluster;
        $row->cname = $cname;
        $row->description = $description;
        $row->dialplan = $dialplan;
        $row->active = 'YES';
        $row->defaultopen = 'YES';
        $row->defaultclosed = 'YES';
        $row->orideopen = $oride ? 'YES' : 'NO';
        $row->orideclosed = $oride ? 'YES' : 'NO';
        $row->z_updater = 'cos-highrisk-seed';
        $row->save();

        return $row;
    }

    private function newShortuid(): string
    {
        try {
            return generate_shortuid();
        } catch (\Throwable $e) {
            $s = strtolower(substr(bin2hex(random_bytes(4)), 0, 6));
            if (! preg_match('/[a-z]/', $s)) {
                $s = 'a'.substr($s, 1);
            }

            return $s;
        }
    }
}
