<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Api\AgentsRosterProcedure;
use Kanboard\Plugin\Agents\Api\AgentsWipProcedure;

// withObject() lets core win a name clash silently and exposes EVERY method of the object,
// private ones included (research #4880). Pin both facts as tests.
class ProcedureSurfaceTest extends Base
{
    const SURFACE = [
        AgentsWipProcedure::class => ['getWipFlags', 'applyWipFix'],
        AgentsRosterProcedure::class => ['adoptAgent', 'createAgent', 'getAgents', 'disableAgent'],
    ];

    public function testProcedureClassesDeclareOnlyTheirRpcMethods(): void
    {
        foreach (self::SURFACE as $class => $methods) {
            $declared = array_map(fn ($m) => $m->getName(), array_filter(
                (new \ReflectionClass($class))->getMethods(),
                fn ($m) => $m->getDeclaringClass()->getName() === $class
            ));
            sort($declared);
            sort($methods);
            $this->assertSame($methods, $declared, $class);
        }
    }

    public function testNoCoreProcedureShadowsOurNames(): void
    {
        $files = glob('app/Api/Procedure/*Procedure.php');
        $this->assertNotEmpty($files, 'run from the Kanboard root');
        foreach ($files as $file) {
            $core = 'Kanboard\\Api\\Procedure\\'.basename($file, '.php');
            foreach (array_merge(...array_values(self::SURFACE)) as $method) {
                $this->assertFalse(method_exists($core, $method), $core.'::'.$method.' would win over the plugin');
            }
        }
    }

    public function testProcedureParamsCarryNoTypeHints(): void
    {
        foreach (self::SURFACE as $class => $methods) {
            foreach ($methods as $m) {
                foreach ((new \ReflectionMethod($class, $m))->getParameters() as $p) {
                    $this->assertNull($p->getType(), $class.'::'.$m.' $'.$p->getName());
                }
            }
        }
    }
}
