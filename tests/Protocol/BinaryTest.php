<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Protocol;

use Innmind\IPC\{
    Protocol\Binary,
    Protocol,
    Message,
    Exception\NoMessage,
};
use Innmind\Filesystem\{
    MediaType\MediaType,
    Stream\StringStream,
};
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class BinaryTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(Protocol::class, new Binary);
    }

    public function testEncode()
    {
        $protocol = new Binary;
        $message = new Message\Generic(
            MediaType::fromString('application/json'),
            Str::of('{"foo":"bar🙏"}')
        );

        $binary = $protocol->encode($message);

        $this->assertInstanceOf(Str::class, $binary);
        $this->assertSame(
            \pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏"}',
            (string) $binary
        );
    }

    public function testDecode()
    {
        $protocol = new Binary;
        $stream = new StringStream(\pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏"}baz');

        $message = $protocol->decode($stream);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('application/json', (string) $message->mediaType());
        $this->assertSame('{"foo":"bar🙏"}', (string) $message->content());
        $this->assertSame('baz', (string) $stream->read(3)); // to verify the protocol didn't read that part
    }

    public function testThrowWhenEmptyStream()
    {
        $protocol = new Binary;

        $this->expectException(NoMessage::class);

        $protocol->decode(new StringStream(''));
    }
}
