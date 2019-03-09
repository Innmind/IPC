<?php
declare(strict_types = 1);

namespace Innmind\IPC\Message;

use Innmind\IPC\Message;
use Innmind\Filesystem\MediaType;
use Innmind\Immutable\Str;

final class MessageReceived implements Message
{
    private $mediaType;
    private $content;

    public function __construct()
    {
        $this->mediaType = new MediaType\MediaType('text', 'plain');
        $this->content = Str::of('innmind/ipc:message.received');
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
        return (string) $this->mediaType === (string) $message->mediaType() &&
            (string) $this->content === (string) $message->content();
    }
}
