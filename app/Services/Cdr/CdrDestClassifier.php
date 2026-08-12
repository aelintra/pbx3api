<?php

namespace App\Services\Cdr;

/**
 * Classify CDR dst for Home “where calls are going” pie.
 *
 * Order: internal → high-cost (velocity prefixes) → international → domestic.
 */
final class CdrDestClassifier
{
    /**
     * @return 'internal'|'high_cost'|'international'|'domestic'|'empty'
     */
    public function classify(string $dst): string
    {
        $dst = trim($dst);
        if ($dst === '') {
            return 'empty';
        }

        if ($this->isInternalDst($dst)) {
            return 'internal';
        }

        if ($this->matchesHighCost($dst)) {
            return 'high_cost';
        }

        if ($this->isInternational($dst)) {
            return 'international';
        }

        return 'domestic';
    }

    /** Same heuristics as VelocityCdrQuery::isInternalDst (keep conservative). */
    public function isInternalDst(string $dst): bool
    {
        $dst = trim($dst);
        if ($dst === '') {
            return true;
        }
        if (preg_match('/^\d{3,6}$/', $dst) === 1) {
            return true;
        }
        if (preg_match('/^[a-z][a-z0-9]{4,7}$/i', $dst) === 1) {
            return true;
        }

        return false;
    }

    private function matchesHighCost(string $dst): bool
    {
        foreach ($this->highCostPrefixes() as $prefix) {
            if ($prefix !== '' && str_starts_with($dst, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function highCostPrefixes(): array
    {
        $raw = (string) config('pbx3_ops.velocity_prefixes', '');
        $out = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Intl access / E.164 whose country code is not the site home CC.
     */
    public function isInternational(string $dst): bool
    {
        $home = preg_replace('/\D+/', '', (string) config('pbx3_cdr.home_country_code', '44')) ?: '44';
        $digits = null;

        if (str_starts_with($dst, '+')) {
            $digits = preg_replace('/\D+/', '', substr($dst, 1)) ?? '';
        } elseif (str_starts_with($dst, '00')) {
            $digits = preg_replace('/\D+/', '', substr($dst, 2)) ?? '';
        } elseif (str_starts_with($dst, '011')) {
            $digits = preg_replace('/\D+/', '', substr($dst, 3)) ?? '';
        }

        if ($digits === null || $digits === '') {
            return false;
        }

        $cc = CountryCallingCodes::match($digits);
        if ($cc === null) {
            return true;
        }

        return $cc !== $home;
    }
}
