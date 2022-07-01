<?php
declare(strict_types = 1);

namespace Innmind\IPC\Protocol;

use Innmind\IPC\{
    Protocol,
    Message,
    Exception\MessageContentTooLong,
    Exception\NoMessage,
    Exception\InvalidMessage,
};
use Innmind\Stream\Readable;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

final class Binary implements Protocol
{
    public function encode(Message $message): Str
    {
        $content = $message->content()->toEncoding('ASCII');
        $mediaType = Str::of($message->mediaType()->toString())->toEncoding('ASCII');

        if ($content->length() > 4_294_967_295) { // unsigned long integer
            throw new MessageContentTooLong((string) $content->length());
        }

        return Str::of('%s%s%s%s%s', 'ASCII')->sprintf(
            \pack('n', $mediaType->length()),
            $mediaType->toString(),
            \pack('N', $content->length()),
            $content->toString(),
            \pack('C', $this->end()),
        );
    }

    public function decode(Readable $stream): Message
    {
        $length = $stream->read(2)->match(
            static fn($length) => $length,
            static fn() => throw new NoMessage,
        );

        if ($length->empty()) {
            throw new NoMessage;
        }

        /** @var positive-int $mediaTypeLength */
        [, $mediaTypeLength] = \unpack('n', $length->toString());
        $mediaType = $stream->read($mediaTypeLength)->match(
            static fn($mediaType) => $mediaType,
            static fn() => throw new InvalidMessage,
        );
        /** @var positive-int $contentLength */
        [, $contentLength] = \unpack('N', $stream->read(4)->match(
            static fn($contentLength) => $contentLength->toString(),
            static fn() => throw new InvalidMessage,
        ));
        $content = $stream->read($contentLength)->match(
            static fn($content) => $content,
            static fn() => throw new InvalidMessage,
        );
        [, $end] = \unpack('C', $stream->read(1)->match(
            static fn($end) => $end->toString(),
            static fn() => throw new InvalidMessage,
        ));

        if (
            $content->toEncoding('ASCII')->length() !== $contentLength ||
            $end !== $this->end()
        ) {
            throw new InvalidMessage($content->toString());
        }

        return new Message\Generic(
            MediaType::of($mediaType->toString()),
            $content,
        );
    }

    private function end(): int
    {
        return 0xCE;
    }
}
