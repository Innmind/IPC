<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Message;

use Innmind\IPC\{
    Message\Generic,
    Message,
};
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class GenericTest extends TestCase
{
    public function testInterface()
    {
        $message = new Generic(
            $mediaType = MediaType::null(),
            $content = Str::of('foo')
        );

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame($mediaType, $message->mediaType());
        $this->assertSame($content, $message->content());
    }

    public function testOf()
    {
        $message = Generic::of('text/plain', 'foo');

        $this->assertInstanceOf(Generic::class, $message);
        $this->assertTrue($message->equals(new Generic(
            MediaType::of('text/plain'),
            Str::of('foo'),
        )));
    }

    public function testEquals()
    {
        $message = new Generic(
            MediaType::of('text/plain'),
            Str::of('watev')
        );
        $same = new Generic(
            MediaType::of('text/plain'),
            Str::of('watev')
        );
        $different = new Message\Generic(
            MediaType::of('text/plain'),
            Str::of('foo'),
        );

        $this->assertTrue($message->equals($same));
        $this->assertFalse($message->equals($different));
    }
}
