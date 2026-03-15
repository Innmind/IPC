<?php
declare(strict_types = 1);

namespace Innmind\IPC\Message;

use Innmind\IPC\Message;
use Innmind\MediaType\{
    MediaType,
    TopLevel,
};
use Innmind\Immutable\Str;

/**
 * @internal
 * @psalm-immutable
 */
enum Protocol implements Implementation
{
    case connectionStart;
    case connectionStartOk;
    case connectionClose;
    case connectionCloseOk;
    case heartbeat;
    case ack;

    /**
     * @internal
     */
    public static function parse(Str $content): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->content()->equals($content)) {
                return $case;
            }
        }

        return null;
    }

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

    public function message(): Message
    {
        return match ($this) {
            self::connectionStart => Message::connectionStart(),
            self::connectionStartOk => Message::connectionStartOk(),
            self::connectionClose => Message::connectionClose(),
            self::connectionCloseOk => Message::connectionCloseOk(),
            self::heartbeat => Message::heartbeat(),
            self::ack => Message::ack(),
        };
    }
}
