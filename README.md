# Inter-Process Communication (IPC)

[![CI](https://github.com/Innmind/IPC/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/Innmind/IPC/actions/workflows/ci.yml)
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
    IPC,
    Process\Name,
    Continuation,
    Server,
    Message,
};
use Innmind\OperatingSystem\Factory;
use Innmind\Immutable\Monoid;

/**
 * @psalm-immutable
 * @implements Monoid<int>
 */
final class Addition implements Monoid
{
    public function identity(): int
    {
        return 0;
    }

    public function combine(mixed $a, mixed $b): int
    {
        return $a + $b;
    }
}

$ipc = IPC::build(Factory::build());
$counter = $ipc
    ->serve(Name::of('a'))
    ->sink(new Addition)
    ->monitor(static fn(int $counter, Server\Continuation $continuation) => match ($counter) {
        42 => $continuation->finish(),
        default => $continuation,
    })
    ->with(
        static fn(Message $message, Continuation $continuation, int $counter) => $continuation
            ->respond($message)
            ->carryWith($counter + 1),
    )
    ->unwrap();
// $counter will always be 42 in this case
```

```php
# Process B
use Innmind\IPC\{
    IPC,
    Process,
    Process\Name,
    Message,
};
use Innmind\OperatingSystem\Factory;
use Innmind\MediaType\{
    MediaType,
    TopLevel,
};
use Innmind\Immutable\{
    Str,
    Sequence,
};

$ipc = IPC::build(Factory::build());
$server = Name::of('a');
$response = $ipc
    ->connectTo($server)
    ->flatMap(
        static fn(Process $process) => $process
            ->send(Sequence::of(
                Message::of(
                    MediaType::from(TopLevel::text, 'plain'),
                    Str::of('hello world'),
                ),
            ))
            ->map(static fn() => $process),
    )
    ->flatMap(fn(Process $process) => $process->wait())
    ->match(
        static fn(Message $message) => 'server responded '.$message->content()->toString(),
        static fn() => 'no response from the server',
    );
print($message);
```

The above example will result in the output `server responded hello world` in the process `B`.

You can run the process `B` `42` times before the server in the process `A` stops.
