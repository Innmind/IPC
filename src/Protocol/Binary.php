<?php
declare(strict_types = 1);

namespace Innmind\IPC\Protocol;

use Innmind\IPC\{
    Protocol,
    Message,
    Exception\MessageContentTooLong,
};
use Innmind\Stream\Readable;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\{
    Str,
    Maybe,
};

final class Binary implements Protocol
{
    public function encode(Message $message): Str
    {
        $content = $message->content()->toEncoding(Str\Encoding::ascii);
        $mediaType = Str::of($message->mediaType()->toString())->toEncoding(Str\Encoding::ascii);

        if ($content->length() > 4_294_967_295) { // unsigned long integer
            throw new MessageContentTooLong((string) $content->length());
        }

        return Str::of('%s%s%s%s%s', Str\Encoding::ascii)->sprintf(
            \pack('n', $mediaType->length()),
            $mediaType->toString(),
            \pack('N', $content->length()),
            $content->toString(),
            \pack('C', $this->end()),
        );
    }

    public function decode(Readable $stream): Maybe
    {
        /** @var Maybe<Message> */
        return $stream
            ->read(2)
            ->filter(static fn($length) => !$length->empty())
            ->map(static function($length): int {
                /** @var positive-int $mediaTypeLength */
                [, $mediaTypeLength] = \unpack('n', $length->toString());

                return $mediaTypeLength;
            })
            ->flatMap(static fn($mediaTypeLength) => $stream->read($mediaTypeLength))
            ->map(static fn($mediaType) => $mediaType->toString())
            ->flatMap(static function($mediaType) use ($stream) {
                return $stream
                    ->read(4)
                    ->map(static function($length): int {
                        /** @var positive-int $contentLength */
                        [, $contentLength] = \unpack('N', $length->toString());

                        return $contentLength;
                    })
                    ->flatMap(static fn($contentLength) => $stream->read($contentLength)->map(
                        static fn($content) => [$mediaType, $contentLength, $content],
                    ));
            })
            ->flatMap(
                // verify the message end boundary is correct
                fn($parsed) => $stream
                    ->read(1)
                    ->map(static function($end): mixed {
                        [, $end] = \unpack('C', $end->toString());

                        return $end;
                    })
                    ->filter(fn($end) => $end === $this->end())
                    ->map(static fn() => $parsed),
            )
            // verify the read content is of the length specified
            ->filter(static fn($parsed) => $parsed[1] === $parsed[2]->toEncoding(Str\Encoding::ascii)->length())
            ->flatMap(
                static fn($parsed) => MediaType::maybe($parsed[0])->map(
                    static fn($mediaType) => new Message\Generic(
                        $mediaType,
                        $parsed[2],
                    ),
                ),
            );
    }

    private function end(): int
    {
        return 0xCE;
    }
}
