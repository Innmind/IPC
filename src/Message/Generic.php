<?php
declare(strict_types = 1);

namespace Innmind\IPC\Message;

use Innmind\IPC\Message;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

/**
 * @psalm-immutable
 */
final class Generic implements Message
{
    private MediaType $mediaType;
    private Str $content;

    public function __construct(MediaType $mediaType, Str $content)
    {
        $this->mediaType = $mediaType;
        $this->content = $content;
    }

    public static function of(string $mediaType, string $content): self
    {
        return new self(
            MediaType::of($mediaType),
            Str::of($content),
        );
    }

    public function mediaType(): MediaType
    {
        return $this->mediaType;
    }

    public function content(): Str
    {
        return $this->content;
    }

    public function equals(Message $message): bool
    {
        return $this->mediaType->toString() === $message->mediaType()->toString() &&
            $this->content->toString() === $message->content()->toString();
    }
}
