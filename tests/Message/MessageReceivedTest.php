<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Message;

use Innmind\IPC\{
    Message\MessageReceived,
    Message\Generic,
    Message,
};
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class MessageReceivedTest extends TestCase
{
    public function testInterface()
    {
        $message = new MessageReceived;

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('text/plain', $message->mediaType()->toString());
        $this->assertSame('innmind/ipc:message.received', $message->content()->toString());
    }

    public function testEquals()
    {
        $message = new MessageReceived;
        $same = new Generic(
            MediaType::of('text/plain'),
            Str::of('innmind/ipc:message.received'),
        );
        $different = new Message\Generic(
            MediaType::of('text/plain'),
            Str::of('foo'),
        );

        $this->assertTrue($message->equals($same));
        $this->assertFalse($message->equals($different));
    }
}
