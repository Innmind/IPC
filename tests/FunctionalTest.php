<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC;

use Innmind\OperatingSystem\Factory;
use Innmind\Server\Control\Server\{
    Command,
    Signal,
};
use Innmind\TimeContinuum\Period\Earth\Second;
use PHPUnit\Framework\TestCase;

class FunctionalTest extends TestCase
{
    public function testBehaviour()
    {
        $os = Factory::build();
        @unlink($os->status()->tmp().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $processes->execute(
            Command::background('php')
                ->withArgument('fixtures/server.php')
        );
        $output = (string) $processes
            ->execute(
                Command::foreground('php')
                    ->withArgument('fixtures/client.php')
            )
            ->wait()
            ->output();

        $this->assertSame('hello world', $output);
    }

    public function testKillServer()
    {
        $os = Factory::build();
        @unlink($os->status()->tmp().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $server = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/eternal-server.php')
        );
        $os->process()->halt(new Second(1));
        $processes->kill($server->pid(), Signal::interrupt());

        $os->process()->halt(new Second(1));
        $this->assertTrue($server->exitCode()->isSuccessful());
        $this->assertFalse(file_exists($os->status()->tmp().'/innmind/ipc/server.sock'));
    }
}
