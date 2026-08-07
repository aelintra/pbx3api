<?php

uses(Tests\TestCase::class);

use App\Http\Controllers\CertificateController;

/**
 * SBC fleet: instance-only LE SANs. Solo: Option A includes tenant FQDNs.
 * @see pbx3/workingdocs/TLS_AND_CERTIFICATES.md §0
 */
test('buildCertificateFqdnList fleet is instance only', function () {
    $ctrl = new class extends CertificateController {
        public function expose(bool $fleet, ?string $primary, array $tenants): array
        {
            return $this->buildCertificateFqdnList($fleet, $primary, $tenants);
        }
    };

    expect($ctrl->expose(true, '08jzwn.pbx3.com', ['dhbm8x.pbx3.com', '0ggybk.pbx3.com']))
        ->toBe(['08jzwn.pbx3.com']);
});

test('buildCertificateFqdnList solo includes tenants', function () {
    $ctrl = new class extends CertificateController {
        public function expose(bool $fleet, ?string $primary, array $tenants): array
        {
            return $this->buildCertificateFqdnList($fleet, $primary, $tenants);
        }
    };

    expect($ctrl->expose(false, 'node.example.com', ['t1.example.com', 't2.example.com']))
        ->toBe(['node.example.com', 't1.example.com', 't2.example.com']);
});

test('buildCertificateFqdnList dedupes primary among tenants', function () {
    $ctrl = new class extends CertificateController {
        public function expose(bool $fleet, ?string $primary, array $tenants): array
        {
            return $this->buildCertificateFqdnList($fleet, $primary, $tenants);
        }
    };

    expect($ctrl->expose(false, 'node.example.com', ['Node.Example.com', 't1.example.com']))
        ->toBe(['node.example.com', 't1.example.com']);
});
