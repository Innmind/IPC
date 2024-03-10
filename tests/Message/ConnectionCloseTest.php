<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Message;

use Innmind\IPC\{
    Message\ConnectionClose,
    Message\Generic,
    Message,
};
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class ConnectionCloseTest extends TestCase
{
    public function testInterface()
    {
        $message = new ConnectionClose;

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('text/plain', $message->mediaType()->toString());
        $this->assertSame('innmind/ipc:connection.close', $message->content()->toString());
    }

    public function testEquals()
    {
        $message = new ConnectionClose;
        $same = new Generic(
            MediaType::of('text/plain'),
            Str::of('innmind/ipc:connection.close'),
        );
        $different = new Generic(
            MediaType::of('text/plain'),
            Str::of('foo'),
        );

        $this->assertTrue($message->equals($same));
        $this->assertFalse($message->equals($different));
    }
}
