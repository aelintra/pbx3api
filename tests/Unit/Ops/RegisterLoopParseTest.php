<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\AsteriskAuthFailureParser;
use App\Services\Ops\IpAllowlist;
use PHPUnit\Framework\TestCase;

class RegisterLoopParseTest extends TestCase
{
    public function test_parse_wrong_password_registration(): void
    {
        $line = "Registration from '\"Phone\" <sip:1102@bzy54n.pbx3.com>' failed for '203.0.113.50:5060' - Wrong password";
        $p = AsteriskAuthFailureParser::parseLine($line);
        $this->assertNotNull($p);
        $this->assertSame('1102', $p['extension']);
        $this->assertSame('203.0.113.50', $p['source_ip']);
    }

    public function test_parse_pjsip_register(): void
    {
        $line = "Request 'REGISTER' from '\"X\" <sip:1103@host>' failed for '198.51.100.9:5060' (callid: abc) - Failed to authenticate";
        $p = AsteriskAuthFailureParser::parseLine($line);
        $this->assertNotNull($p);
        $this->assertSame('1103', $p['extension']);
        $this->assertSame('198.51.100.9', $p['source_ip']);
    }

    public function test_parse_security_event(): void
    {
        $line = 'SecurityEvent="InvalidPassword",EventTV="2026-07-16",AccountID="1102",RemoteAddress="IPV4/UDP/203.0.113.50/5060"';
        $p = AsteriskAuthFailureParser::parseLine($line);
        $this->assertNotNull($p);
        $this->assertSame('1102', $p['extension']);
        $this->assertSame('203.0.113.50', $p['source_ip']);
    }

    public function test_cidrs(): void
    {
        $list = IpAllowlist::parseIgnoreipLine('127.0.0.1 203.0.113.0/24');
        $this->assertTrue(IpAllowlist::contains($list, '203.0.113.50'));
        $this->assertFalse(IpAllowlist::contains($list, '198.51.100.1'));
    }

    public function test_endpoint_resolve_unknown_passthrough(): void
    {
        // No DB row required: unknown auth id returns itself.
        $r = \App\Services\Ops\EndpointUidResolver::resolve('no-such-uid');
        $this->assertSame('no-such-uid', $r['extension']);
        $this->assertSame('no-such-uid', $r['endpoint_uid']);
        $this->assertSame('', $r['endpoint_name']);
    }
}
