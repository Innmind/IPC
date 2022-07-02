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
    Maybe,
    Sequence,
};

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = IPC::build($os);
$process = $ipc->wait(new Name('server'))->match(
    static fn($process) => $process,
    static fn() => null,
);
$process->send(Sequence::of(new Message\Generic(
    MediaType::of('text/plain'),
    Str::of('hello world')
)));
$_ = $process
    ->wait()
    ->flatMap(
        static fn($message) => $process
            ->wait()
            ->otherwise(static fn() => Maybe::just($message)),
    )
    ->match(
        static fn($message) => print($message->content()->toString()),
        static fn() => null,
    );
