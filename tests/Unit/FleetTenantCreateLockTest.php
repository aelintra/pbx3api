<?php

uses(Tests\TestCase::class);

use App\Services\Fleet\FleetPostureService;

/**
 * Fleet-first tenant create: Sanctum Create/Delete locked when posture says fleet.
 * Gatekeeper POST /fleet/tenants has no Sanctum user — lock must not apply there.
 */
test('FleetPostureService treats PBX3_FLEET_MODE as fleet node', function () {
    config(['pbx3_fleet.mode' => true]);

    expect(app(FleetPostureService::class)->isFleetNode())->toBeTrue();
});

test('FleetPostureService treats unset mode without egress as solo', function () {
    config(['pbx3_fleet.mode' => false]);
    config(['pbx3_fleet.egress_trunk_pkey' => '__no_such_egress__']);

    // No trunks table in this unit context — hasActiveEgressTrunk may throw or return false.
    // Bind a stub that only checks mode when mode is false and DB is unavailable.
    $svc = new class extends FleetPostureService {
        public function hasActiveEgressTrunk(): bool
        {
            return false;
        }
    };

    expect($svc->isFleetNode())->toBeFalse();
});
