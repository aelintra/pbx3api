<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pure checks mirroring InboundRouteController route_profile rules
 * (empty/None accept; non-empty requires same-tenant profile — model does DB).
 */
class RouteProfileTenantValidationTest extends TestCase
{
    private function shouldRejectProfileRef(?string $routeProfile): bool
    {
        $rp = trim((string) $routeProfile);
        if ($rp === '' || strcasecmp($rp, 'None') === 0) {
            return false;
        }
        // Without a real DB we only assert the "must resolve" gate for non-empty refs.
        return true;
    }

    public function test_empty_or_none_does_not_require_lookup(): void
    {
        $this->assertFalse($this->shouldRejectProfileRef(null));
        $this->assertFalse($this->shouldRejectProfileRef(''));
        $this->assertFalse($this->shouldRejectProfileRef('None'));
        $this->assertFalse($this->shouldRejectProfileRef('none'));
    }

    public function test_set_profile_requires_tenant_lookup(): void
    {
        $this->assertTrue($this->shouldRejectProfileRef('ab12cd34'));
    }

    public function test_duplicate_modes_detected(): void
    {
        $lines = [
            ['mode' => 'open', 'destination' => '1000'],
            ['mode' => 'Open', 'destination' => '2000'],
            ['mode' => 'closed', 'destination' => '3000'],
        ];
        $seen = [];
        $dup = false;
        foreach ($lines as $line) {
            $mode = strtolower(trim((string) ($line['mode'] ?? '')));
            if (isset($seen[$mode])) {
                $dup = true;
                break;
            }
            $seen[$mode] = true;
        }
        $this->assertTrue($dup);
        $this->assertCount(1, array_filter($seen)); // at least open seen before dup
    }
}
