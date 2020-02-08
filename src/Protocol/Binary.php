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
            \pack('C', $this->end())
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

        /** @var int $mediaTypeLength */
        [, $mediaTypeLength] = \unpack('n', $length->toString());
        $mediaType = $stream->read($mediaTypeLength);
        /** @var int $contentLength */
        [, $contentLength] = \unpack('N', $stream->read(4)->toString());
        $content = $stream->read($contentLength);
        [, $end] = \unpack('C', $stream->read(1)->toString());

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
