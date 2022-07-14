<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC;

use Innmind\IPC\{
    Factory,
    IPC,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\Path;
use PHPUnit\Framework\TestCase;

class FactoryTest extends TestCase
{
    public function testBuild()
    {
        $this->assertInstanceOf(
            IPC::class,
            Factory::build(
                $this->createMock(OperatingSystem::class),
                Path::of('/tmp/innmind/ipc/'),
            ),
        );
    }
}
