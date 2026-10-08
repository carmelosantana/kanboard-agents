<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Plugin;

// Tag == plugin.json == getPluginVersion(): drift across the three is the most common release defect.
class PluginVersionTest extends Base
{
    public function testVersionIsAlignedAt033(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $this->assertSame('0.3.3', $json['version']);
        $this->assertSame($json['version'], (new Plugin($this->container))->getPluginVersion());
    }
}
