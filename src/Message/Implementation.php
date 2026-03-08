<?php
declare(strict_types = 1);

namespace Innmind\IPC\Message;

use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

interface Implementation
{
    public function mediaType(): MediaType;
    public function content(): Str;
}
