<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Message;

use Innmind\IPC\{
    Message\ConnectionCloseOk,
    Message\Generic,
    Message,
};
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class ConnectionCloseOkTest extends TestCase
{
    public function testInterface()
    {
        $message = new ConnectionCloseOk;

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('text/plain', (string) $message->mediaType());
        $this->assertSame('innmind/ipc:connection.close-ok', (string) $message->content());
    }

    public function testEquals()
    {
        $message = new ConnectionCloseOk;
        $same = new Generic(
            MediaType::fromString('text/plain'),
            Str::of('innmind/ipc:connection.close-ok')
        );
        $different = $this->createMock(Message::class);

        $this->assertTrue($message->equals($same));
        $this->assertFalse($message->equals($different));
    }
}
