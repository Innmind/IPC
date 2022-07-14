# Inter-Process Communication (IPC)

[![Build Status](https://github.com/Innmind/IPC/workflows/CI/badge.svg?branch=master)](https://github.com/Innmind/IPC/actions?query=workflow%3ACI)
[![codecov](https://codecov.io/gh/Innmind/IPC/branch/develop/graph/badge.svg)](https://codecov.io/gh/Innmind/IPC)
[![Type Coverage](https://shepherd.dev/github/Innmind/IPC/coverage.svg)](https://shepherd.dev/github/Innmind/IPC)

Library to abstract the communication between processes by using message passing over unix sockets.

## Installation

```sh
composer require innmind/ipc
```

## Usage

```php
# Process A
use Innmind\IPC\{
    Factory as IPC,
    Process\Name,
};
use Innmind\OperatingSystem\Factory;

$ipc = IPC::build(Factory::build());
$counter = $ipc->listen(Name::of('a'))(0, static function($message, $continuation, $counter): void {
    if ($counter === 42) {
        return $continuation->stop($counter);
    }

    return $continuation->respond($counter + 1, $message);
})->match(
    static fn($counter) => $counter,
    static fn() => throw new \RuntimeException('Unable to start the server'),
);
// $counter will always be 42 in this case
```

```php
# Process B
use Innmind\IPC\{
    Factory as IPC,
    Process\Name,
    Message\Generic as Message,
};
use Innmind\OperatingSystem\Factory;
use Innmind\Immutable\Sequence;

$ipc = IPC::build(Factory::build());
$server = Name::of('a');
$ipc
    ->wait(Name::of('a'))
    ->flatMap(fn($process) => $process->send(Sequence::of(
        Message::of('text/plain', 'hello world'),
    )))
    ->flatMap(fn($process) => $process->wait())
    ->match(
        static fn($message) => print('server responded '.$message->content()->toString()),
        static fn() => print('no response from the server'),
    );
```

The above example will result in the output `server responded hello world` in the process `B`.

You can run the process `B` `42` times before the server in the process `A` stops.
