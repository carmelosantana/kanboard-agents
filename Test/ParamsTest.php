<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\Params;

class ParamsTest extends Base
{
    public function testIdAcceptsPositiveIntsAndDigitStringsOnly(): void
    {
        $this->assertSame(7, Params::id(7));
        $this->assertSame(7, Params::id('7'));
        foreach ([0, -1, '0x7', '7.0', ' 7', 7.0, true, null, [], ['7']] as $bad) {
            $this->assertSame(0, Params::id($bad), json_encode($bad));
        }
        $this->assertSame(0, Params::id('0'));
    }

    public function testIdListKeepsNullAndDedupes(): void
    {
        $this->assertNull(Params::idList(null, 'project_ids'));
        $this->assertSame([], Params::idList([], 'project_ids'));
        $this->assertSame([3, 1], Params::idList([3, '1', 3], 'project_ids'));
    }

    public function testIdListRejectsNonArraysAndBadItems(): void
    {
        foreach (['1,2', 5, [1, 'x'], [0]] as $bad) {
            try {
                Params::idList($bad, 'project_ids');
                $this->fail('accepted '.json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('project_ids', $e->getMessage());
            }
        }
    }

    public function testFlagAcceptsBoolsAndZeroOne(): void
    {
        $this->assertTrue(Params::flag(true, 'x'));
        $this->assertFalse(Params::flag(false, 'x'));
        $this->assertTrue(Params::flag(1, 'x'));
        $this->assertFalse(Params::flag('0', 'x'));
        $this->expectException(\InvalidArgumentException::class);
        Params::flag('yes', 'x');
    }
}
