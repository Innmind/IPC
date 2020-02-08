<?php
declare(strict_types = 1);

namespace Innmind\IPC\Message;

use Innmind\IPC\Message;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

final class ConnectionStartOk implements Message
{
    private MediaType $mediaType;
    private Str $content;

    public function __construct()
    {
        $this->mediaType = new MediaType('text', 'plain');
        $this->content = Str::of('innmind/ipc:connection.start-ok');
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
