<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Protocol;

use Innmind\IPC\{
    Protocol\Binary,
    Protocol,
    Message,
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

        $message = $protocol->decode($stream)->match(
            static fn($message) => $message,
            static fn() => null,
        );

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('application/json', $message->mediaType()->toString());
        $this->assertSame('{"foo":"bar🙏"}', $message->content()->toString());
        $this->assertSame('baz', $stream->read(3)->match(
            static fn($chunk) => $chunk->toString(),
            static fn() => null,
        )); // to verify the protocol didn't read that part
    }

    public function testReturnNothingWhenEmptyStream()
    {
        $protocol = new Binary;

        $this->assertNull($protocol->decode(Stream::ofContent(''))->match(
            static fn($message) => $message,
            static fn() => null,
        ));
    }

    public function testReturnNothingWhenMessageContentNotOfExceptedSize()
    {
        $protocol = new Binary;
        $stream = Stream::ofContent(\pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏'.\pack('C', 0xCE).'baz');

        $this->assertNull($protocol->decode($stream)->match(
            static fn($message) => $message,
            static fn() => null,
        ));
    }

    public function testReturnNothingWhenMessageNotEndedWithSpecialCharacter()
    {
        $protocol = new Binary;
        $stream = Stream::ofContent(\pack('n', 16).'application/json'.\pack('N', 17).'{"foo":"bar🙏"}baz');

        $this->assertNull($protocol->decode($stream)->match(
            static fn($message) => $message,
            static fn() => null,
        ));
    }
}
