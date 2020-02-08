# Inter-Process Communication (IPC)

[![Build Status](https://github.com/Innmind/IPC/workflows/CI/badge.svg)](https://github.com/Innmind/IPC/actions?query=workflow%3ACI)
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
use function Innmind\IPC\bootstrap;
use Innmind\IPC\Process\Name;
use Innmind\OperatingSystem\Factory;

$ipc = bootstrap(Factory::build());
$ipc->listen(new Name('a'))(static function($message, $client): void {
    $client->send($message);
});
```

```php
# Process B
use function Innmind\IPC\bootstrap;
use Innmind\IPC\{
    Process\Name,
    Message\Generic as Message,
};
use Innmind\OperatingSystem\Factory;
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;

$ipc = bootstrap(Factory::build());
$server = new Name('a');
$ipc->wait($server);
$process = $ipc->get($server);
$process->send(new Message(
    MediaType::fromString('text/plain'),
    Str::of('hello world')
));
$message = $process->wait();
echo 'server responded '.$message->content();
```

The above example will result in the output `server responded hello world` in the process `B`.
