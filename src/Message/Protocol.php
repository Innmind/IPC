<?php
declare(strict_types = 1);

namespace Innmind\IPC\Message;

use Innmind\MediaType\{
    MediaType,
    TopLevel,
};
use Innmind\Immutable\Str;

enum Protocol implements Implementation
{
    case connectionStart;
    case connectionStartOk;
    case connectionClose;
    case connectionCloseOk;
    case heartbeat;
    case ack;

    #[\Override]
    public function mediaType(): MediaType
    {
        return MediaType::from(
            TopLevel::text,
            'plain',
        );
    }

    #[\Override]
    public function content(): Str
    {
        return Str::of(match ($this) {
            self::connectionStart => 'innmind/ipc:connection.start',
            self::connectionStartOk => 'innmind/ipc:connection.start-ok',
            self::connectionClose => 'innmind/ipc:connection.close',
            self::connectionCloseOk => 'innmind/ipc:connection.close-ok',
            self::heartbeat => 'innmind/ipc:heartbeat',
            self::ack => 'innmind/ipc:ack',
        });
    }
}
