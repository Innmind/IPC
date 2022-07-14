<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

/**
 * @psalm-immutable
 */
interface Message
{
    public function mediaType(): MediaType;
    public function content(): Str;
    public function equals(self $message): bool;
}
