<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\IPC\Message\{
    Implementation,
    Protocol,
    Generic,
};
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

/**
 * @psalm-immutable
 */
final class Message
{
    private function __construct(
        private Implementation $implementation,
    ) {
    }

    /**
     * @psalm-pure
     */
    public static function of(MediaType $mediaType, Str $content): self
    {
        return new self(new Generic($mediaType, $content));
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function connectionStart(): self
    {
        return new self(Protocol::connectionStart);
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function connectionStartOk(): self
    {
        return new self(Protocol::connectionStartOk);
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function connectionClose(): self
    {
        return new self(Protocol::connectionClose);
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function connectionCloseOk(): self
    {
        return new self(Protocol::connectionCloseOk);
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function heartbeat(): self
    {
        return new self(Protocol::heartbeat);
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function ack(): self
    {
        return new self(Protocol::ack);
    }

    public function mediaType(): MediaType
    {
        return $this->implementation->mediaType();
    }

    public function content(): Str
    {
        return $this->implementation->content();
    }

    public function equals(self $message): bool
    {
        $self = $this->implementation;
        $other = $message->implementation;

        if ($self instanceof Protocol && $other instanceof Protocol) {
            return $self === $other;
        }

        if ($other instanceof Protocol) {
            // This is to avoid reading the message content when checking if the
            // message is a protocol one. If it's a protocol one $self must be
            // correctly decoded as Message\Protocol by the protocol decoder.
            return false;
        }

        if ($self->mediaType()->toString() !== $other->mediaType()->toString()) {
            return false;
        }

        return $self->content()->equals($other->content());
    }
}
