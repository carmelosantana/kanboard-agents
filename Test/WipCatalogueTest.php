<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\WipCatalogue;

class WipCatalogueTest extends Base
{
    private function write(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wipcat');
        file_put_contents($path, json_encode($data));
        return $path;
    }

    private function shipped(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../catalogue.json'), true);
    }

    public function testShippedCatalogueLoadsAllTenFlagsInOrder(): void
    {
        $c = new WipCatalogue();
        $this->assertSame(WipCatalogue::KEYS, array_keys($c->flags()));
        $this->assertSame(5, $c->weight('stale'));
        $this->assertSame(1, $c->weight('noowner'));
        $this->assertTrue($c->flag('donesubs')['oneclick']);
        $this->assertFalse($c->flag('merged')['oneclick']);
        $this->assertSame('unowned', $c->flag('noowner')['applies_to']);
        $this->assertSame(3, $c->threshold('stale_days'));
        $this->assertSame(30, $c->threshold('closed_lookback_days'));
    }

    public function testVersionIsSha256OfTheFileBytes(): void
    {
        $path = __DIR__.'/../catalogue.json';
        $this->assertSame('sha256:'.hash_file('sha256', $path), (new WipCatalogue())->version());
    }

    public function testUnavailableFlagsAreTheOnesWithRequires(): void
    {
        $c = new WipCatalogue();
        $this->assertSame(['merged', 'ended', 'unverified', 'timemiss'], $c->unavailable());
        $this->assertSame(['stale', 'donesubs', 'offboard', 'mismatch', 'blocked', 'noowner'], $c->available());
    }

    public function testRejectsMissingFlag(): void
    {
        $d = $this->shipped();
        array_pop($d['flags']);
        $this->expectException(\RuntimeException::class);
        new WipCatalogue($this->write($d));
    }

    public function testRejectsDuplicateFlag(): void
    {
        $d = $this->shipped();
        $d['flags'][9] = $d['flags'][0];
        $this->expectException(\RuntimeException::class);
        new WipCatalogue($this->write($d));
    }

    public function testRejectsStringWeight(): void
    {
        $d = $this->shipped();
        $d['flags'][0]['weight'] = '5';
        $this->expectException(\RuntimeException::class);
        new WipCatalogue($this->write($d));
    }

    public function testRejectsMissingThreshold(): void
    {
        $d = $this->shipped();
        unset($d['thresholds']['stale_days']);
        $this->expectException(\RuntimeException::class);
        new WipCatalogue($this->write($d));
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        new WipCatalogue('/nonexistent/catalogue.json');
    }
}
