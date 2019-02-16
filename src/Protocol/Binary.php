<?php
declare(strict_types = 1);

namespace Innmind\IPC\Protocol;

use Innmind\IPC\{
    Protocol,
    Message,
    Exception\MessageContentTooLong,
    Exception\NoMessage,
};
use Innmind\Stream\Readable;
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;

final class Binary implements Protocol
{
    public function encode(Message $message): Str
    {
        $content = $message->content()->toEncoding('ASCII');
        $mediaType = Str::of((string) $message->mediaType())->toEncoding('ASCII');

        if ($content->length() > 4294967295) { // unsigned long integer
            throw new MessageContentTooLong((string) $content->length());
        }

        return Str::of('%s%s%s%s')->sprintf(
            \pack('n', $mediaType->length()),
            $mediaType,
            \pack('N', $content->length()),
            $content
        );
    }

    /**
     * {@inheritdoc}
     */
    public function decode(Readable $stream): Message
    {
        $length = $stream->read(2);

        if ($length->empty()) {
            throw new NoMessage;
        }

        [, $mediaTypeLength] = \unpack('n', (string) $length);
        $mediaType = $stream->read($mediaTypeLength);
        [, $contentLength] = \unpack('N', (string) $stream->read(4));
        $content = $stream->read($contentLength);

        return new Message\Generic(
            MediaType::fromString((string) $mediaType),
            $content
        );
    }
}
