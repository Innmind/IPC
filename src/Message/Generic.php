<?php
declare(strict_types = 1);

namespace Innmind\IPC\Message;

use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

/**
 * @internal
 */
final class Generic implements Implementation
{
    public function __construct(
        private MediaType $mediaType,
        private Str $content,
    ) {
    }

    #[\Override]
    public function mediaType(): MediaType
    {
        return $this->mediaType;
    }

    #[\Override]
    public function content(): Str
    {
        return $this->content;
    }
}
