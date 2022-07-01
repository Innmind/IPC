<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Protocol;

use Innmind\IPC\{
    Protocol\Binary,
    Protocol,
    Message,
    Exception\NoMessage,
    Exception\InvalidMessage,
};
use Innmind\MediaType\MediaType;
use Innmind\Stream\Readable\Stream;
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
            MediaType::of('application/json'),
            Str::of('{"foo":"bar🙏"}'),
        );

        $binary = $protocol->encode($message);

        $this->assertInstanceOf(Str::class, $binary);
        $this->assertSame(
            \pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏"}'.\pack('C', 0xCE),
            $binary->toString(),
        );
        $this->assertSame('ASCII', $binary->encoding()->toString());
    }

    public function testDecode()
    {
        $protocol = new Binary;
        $stream = Stream::ofContent(\pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏"}'.\pack('C', 0xCE).'baz');

        $message = $protocol->decode($stream);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('application/json', $message->mediaType()->toString());
        $this->assertSame('{"foo":"bar🙏"}', $message->content()->toString());
        $this->assertSame('baz', $stream->read(3)->match(
            static fn($chunk) => $chunk->toString(),
            static fn() => null,
        )); // to verify the protocol didn't read that part
    }

    public function testThrowWhenEmptyStream()
    {
        $protocol = new Binary;

        $this->expectException(NoMessage::class);

        $protocol->decode(Stream::ofContent(''));
    }

    public function testThrowWhenMessageContentNotOfExceptedSize()
    {
        $protocol = new Binary;
        $stream = Stream::ofContent(\pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏'.\pack('C', 0xCE).'baz');

        $this->expectException(InvalidMessage::class);
        $this->expectExceptionMessage('{"foo":"bar🙏'.\pack('C', 0xCE).'b');

        $protocol->decode($stream);
    }

    public function testThrowWhenMessageNotEndedWithSpecialCharacter()
    {
        $protocol = new Binary;
        $stream = Stream::ofContent(\pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏"}baz');

        $this->expectException(InvalidMessage::class);
        $this->expectExceptionMessage('{"foo":"bar🙏"}');

        $protocol->decode($stream);
    }
}
