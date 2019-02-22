<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Filesystem\MediaType;
use Innmind\Immutable\Str;

interface Message
{
    public function mediaType(): MediaType;
    public function content(): Str;
    public function equals(self $message): bool;
}
