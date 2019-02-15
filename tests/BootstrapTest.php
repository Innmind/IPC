<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC;

use function Innmind\IPC\bootstrap;
use Innmind\IPC\IPC;
use Innmind\OperatingSystem\OperatingSystem;
use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            IPC::class,
            bootstrap($this->createMock(OperatingSystem::class))
        );
    }
}
