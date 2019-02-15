<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Message;

use Innmind\IPC\{
    Message\Generic,
    Message,
};
use Innmind\Filesystem\MediaType;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class GenericTest extends TestCase
{
    public function testInterface()
    {
        $message = new Generic(
            $mediaType = $this->createMock(MediaType::class),
            $content = Str::of('foo')
        );

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame($mediaType, $message->mediaType());
        $this->assertSame($content, $message->content());
    }
}
