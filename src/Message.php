<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

final class Message
{
    private function __construct()
    {
    }

    public function mediaType(): MediaType
    {
        return MediaType::null();
    }

    public function content(): Str
    {
        return Str::of('');
    }

    public function equals(self $message): bool
    {
        return false;
    }
}
