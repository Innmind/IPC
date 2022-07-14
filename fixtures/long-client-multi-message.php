<?php
declare(strict_types = 1);

use Innmind\IPC\{
    Factory as IPC,
    Message,
    Process\Name,
};
use Innmind\OperatingSystem\Factory;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\{
    Str,
    Sequence,
};

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = IPC::build($os);
$process = $ipc->wait(new Name('server'))->match(
    static fn($process) => $process,
    static fn() => null,
);
$_ = $process
    ->send(Sequence::of(
        new Message\Generic(
            MediaType::of('text/plain'),
            Str::of('hello world'),
        ),
        new Message\Generic(
            MediaType::of('text/plain'),
            Str::of('second'),
        ),
        new Message\Generic(
            MediaType::of('text/plain'),
            Str::of('third'),
        ),
    ))
    ->flatMap(static fn($process) => $process->wait())
    ->match(
        static fn() => null,
        static fn() => null,
    );
