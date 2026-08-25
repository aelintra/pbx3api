<?php

use App\Support\FirewallAllowRule;

test('isValidFrom accepts any ipv4 ipv6 and cidrs', function () {
    expect(FirewallAllowRule::isValidFrom('any'))->toBeTrue()
        ->and(FirewallAllowRule::isValidFrom('192.168.1.85'))->toBeTrue()
        ->and(FirewallAllowRule::isValidFrom('10.0.0.0/8'))->toBeTrue()
        ->and(FirewallAllowRule::isValidFrom('2001:db8::1'))->toBeTrue()
        ->and(FirewallAllowRule::isValidFrom('2001:db8::/32'))->toBeTrue()
        ->and(FirewallAllowRule::isValidFrom('::1'))->toBeTrue();
});

test('isValidFrom rejects garbage', function () {
    expect(FirewallAllowRule::isValidFrom('not-an-ip'))->toBeFalse()
        ->and(FirewallAllowRule::isValidFrom('2001:db8::/999'))->toBeFalse()
        ->and(FirewallAllowRule::isValidFrom('sbc.example.com'))->toBeFalse()
        ->and(FirewallAllowRule::isValidFrom(''))->toBeFalse();
});

test('validateShape port rules', function () {
    expect(FirewallAllowRule::validateShape('tcp', '44300', 'any'))->toBeNull()
        ->and(FirewallAllowRule::validateShape('udp', '10000:20000', '192.168.1.0/24'))->toBeNull()
        ->and(FirewallAllowRule::validateShape('icmp', '', 'any'))->toBeNull()
        ->and(FirewallAllowRule::validateShape('all', '', 'any'))->toBeNull()
        ->and(FirewallAllowRule::validateShape('tcp', '', 'any'))->not->toBeNull()
        ->and(FirewallAllowRule::validateShape('all', '22', 'any'))->not->toBeNull()
        ->and(FirewallAllowRule::validateShape('tcp', '22', 'bad'))->not->toBeNull();
});

test('legacy shorewall lines detection', function () {
    expect(FirewallAllowRule::looksLikeLegacyShorewallLines(['ACCEPT net $FW tcp 22']))->toBeTrue()
        ->and(FirewallAllowRule::looksLikeLegacyShorewallLines([['action' => 'allow']]))->toBeFalse()
        ->and(FirewallAllowRule::looksLikeLegacyShorewallLines([]))->toBeFalse();
});
