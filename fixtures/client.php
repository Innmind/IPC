<?php
declare(strict_types = 1);

require __DIR__.'/../vendor/autoload.php';

use Innmind\IPC\{
    IPC,
    Process,
    Message,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\Path;
use Innmind\MediaType\{
    MediaType,
    TopLevel,
};
use Innmind\Immutable\{
    Str,
    Sequence,
};

$os = OperatingSystem::new();
$message = IPC::of(
    $os,
    $os->status()->tmp()->resolve(Path::of('innnmind/ipc/')),
)
    ->connectTo(Process\Name::of('server'))
    ->flatMap(
        static fn($process) => $process
            ->send(Sequence::of(Message::of(
                MediaType::from(TopLevel::text, 'plain'),
                Str::of('hello world'),
            )))
            ->map(static fn() => $process),
    )
    ->flatMap(
        static fn($process) => $process
            ->wait()
            ->flatMap(
                static fn($message) => $process
                    ->close()
                    ->map(static fn() => $message),
            ),
    )
    ->unwrap();
echo $message->content()->toString();
