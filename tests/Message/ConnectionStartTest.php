<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Message;

use Innmind\IPC\{
    Message\ConnectionStart,
    Message\Generic,
    Message,
};
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class ConnectionStartTest extends TestCase
{
    public function testInterface()
    {
        $message = new ConnectionStart;

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('text/plain', (string) $message->mediaType());
        $this->assertSame('innmind/ipc:connection.start', (string) $message->content());
    }

    public function testEquals()
    {
        $message = new ConnectionStart;
        $same = new Generic(
            MediaType::fromString('text/plain'),
            Str::of('innmind/ipc:connection.start')
        );
        $different = $this->createMock(Message::class);

        $this->assertTrue($message->equals($same));
        $this->assertFalse($message->equals($different));
    }
}
