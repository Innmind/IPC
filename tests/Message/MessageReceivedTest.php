<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Message;

use Innmind\IPC\{
    Message\MessageReceived,
    Message\Generic,
    Message,
};
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class MessageReceivedTest extends TestCase
{
    public function testInterface()
    {
        $message = new MessageReceived;

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('text/plain', (string) $message->mediaType());
        $this->assertSame('innmind/ipc:message.received', (string) $message->content());
    }

    public function testEquals()
    {
        $message = new MessageReceived;
        $same = new Generic(
            MediaType::fromString('text/plain'),
            Str::of('innmind/ipc:message.received')
        );
        $different = $this->createMock(Message::class);

        $this->assertTrue($message->equals($same));
        $this->assertFalse($message->equals($different));
    }
}
