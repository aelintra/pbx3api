<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Documents open-destination product rule for greenfield inbound / profiles.
 * Controller enforcement lives in InboundRouteController + RouteProfileController.
 */
class RouteProfileOpenRequiredTest extends TestCase
{
    public function test_none_destination_tokens(): void
    {
        $isNone = static function (?string $dest): bool {
            $t = trim((string) $dest);

            return $t === '' || strcasecmp($t, 'None') === 0;
        };

        $this->assertTrue($isNone(null));
        $this->assertTrue($isNone(''));
        $this->assertTrue($isNone('None'));
        $this->assertTrue($isNone('none'));
        $this->assertFalse($isNone('1000'));
        $this->assertFalse($isNone('Operator'));
    }

    public function test_closed_defaults_to_open_when_none(): void
    {
        $open = '1000';
        $closed = 'None';
        if ($closed === '' || strcasecmp($closed, 'None') === 0) {
            $closed = $open;
        }
        $this->assertSame('1000', $closed);
    }

    public function test_lines_must_include_open_mode(): void
    {
        $modes = ['closed', 'lunch'];
        $this->assertFalse(in_array('open', $modes, true));

        $modes[] = 'open';
        $this->assertTrue(in_array('open', $modes, true));
    }
}
