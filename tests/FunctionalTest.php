<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC;

use Innmind\OperatingSystem\Factory;
use Innmind\Server\Control\Server\Command;
use Innmind\TimeContinuum\Period\Earth\Second;
use PHPUnit\Framework\TestCase;

class FunctionalTest extends TestCase
{
    public function testBehaviour()
    {
        $os = Factory::build();
        $processes = $os->control()->processes();
        $processes->execute(
            Command::background('php')
                ->withArgument('fixtures/server.php')
        );
        $os->process()->halt(new Second(1));
        $output = (string) $processes
            ->execute(
                Command::foreground('php')
                    ->withArgument('fixtures/client.php')
            )
            ->wait()
            ->output();

        $this->assertSame("hello world from server\n", $output);
    }
}
