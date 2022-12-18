<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC;

use Innmind\OperatingSystem\Factory;
use Innmind\Server\Control\Server\{
    Command,
    Signal,
};
use Innmind\TimeContinuum\Earth\Period\Second;
use PHPUnit\Framework\TestCase;

class FunctionalTest extends TestCase
{
    public function testBehaviour()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $processes->execute(
            Command::background('php')
                ->withArgument('fixtures/server.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
        );
        $process = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
        );
        $process->wait();
        $output = $process->output()->toString();

        $this->assertSame('hello world', $output);
    }

    public function testKillServer()
    {
        if (\getenv('CI')) {
            return;
        }

        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $server = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/eternal-server.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
        );
        $os->process()->halt(new Second(1));
        $processes->kill(
            $server->pid()->match(
                static fn($pid) => $pid,
                static fn() => null,
            ),
            Signal::interrupt,
        );
        $this->assertTrue($server->wait()->match(
            static fn() => true,
            static fn() => false,
        ));

        $this->assertFalse(\file_exists($os->status()->tmp()->toString().'/innmind/ipc/server.sock'));
    }
}
