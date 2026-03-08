<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\IO\Frame;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\{
    Str,
    Attempt,
};

final class Protocol
{
    /**
     * @param Frame<Message> $frame
     */
    private function __construct(
        private Frame $frame,
    ) {
    }

    public static function binary(): self
    {
        $frame = Frame::chunk(2)
            ->strict()
            ->map(static function($length) {
                /**
                 * @psalm-suppress PossiblyInvalidArrayAccess Todo apply a predicate
                 * @var int<1, max> $mediaTypeLength
                 */
                [, $mediaTypeLength] = \unpack('n', $length->toString());

                return $mediaTypeLength;
            })
            ->flatMap(
                static fn($mediaTypeLength) => Frame::chunk($mediaTypeLength)->strict(),
            )
            ->map(static fn($mediaType) => $mediaType->toString())
            ->flatMap(
                static fn($mediaType) => Frame::chunk(4)
                    ->strict()
                    ->map(static function($length): int {
                        /**
                         * @psalm-suppress PossiblyInvalidArrayAccess Todo apply a predicate
                         * @var int<0, max> $contentLength
                         */
                        [, $contentLength] = \unpack('N', $length->toString());

                        return $contentLength;
                    })
                    ->flatMap(static fn($contentLength) => match ($contentLength) {
                        0 => Frame::just([$mediaType, Str::of('')]),
                        default => Frame::chunk($contentLength)
                            ->strict()
                            ->map(static fn($content) => [
                                $mediaType,
                                $content,
                            ]),
                    }),
            )
            ->flatMap(
                // verify the message end boundary is correct
                static fn($parsed) => Frame::chunk(1)
                    ->strict()
                    ->map(static function($end): mixed {
                        /** @psalm-suppress PossiblyInvalidArrayAccess Todo apply a predicate */
                        [, $end] = \unpack('C', $end->toString());

                        return $end;
                    })
                    ->filter(static fn($end) => $end === self::end())
                    ->map(static fn() => $parsed),
            )
            ->flatMap(
                static fn($parsed) => match ($protocol = Message\Protocol::parse($parsed[1])) {
                    null => Frame::maybe(
                        MediaType::maybe($parsed[0])->map(
                            static fn($mediaType) => Message::of(
                                $mediaType,
                                $parsed[1],
                            ),
                        ),
                    ),
                    default => Frame::just($protocol->message()),
                },
            );

        return new self($frame);
    }

    /**
     * @return Attempt<Str>
     */
    public function encode(Message $message): Attempt
    {
        $content = $message->content()->toEncoding(Str\Encoding::ascii);
        $mediaType = Str::of($message->mediaType()->toString())->toEncoding(Str\Encoding::ascii);
        $length = $content->length();

        if ($length > 4_294_967_295) { // unsigned long integer
            return Attempt::error(new \RuntimeException(\sprintf(
                'Message being sent is too long (length %s)',
                $length,
            )));
        }

        return Attempt::result(Str::of('%s%s%s%s%s', Str\Encoding::ascii)->sprintf(
            \pack('n', $mediaType->length()),
            $mediaType->toString(),
            \pack('N', $content->length()),
            $content->toString(),
            \pack('C', self::end()),
        ));
    }

    /**
     * @return Frame<Message>
     */
    public function frame(): Frame
    {
        return $this->frame;
    }

    private static function end(): int
    {
        return 0xCE;
    }
}
