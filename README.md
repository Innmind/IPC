# Inter-Process Communication (IPC)

| `develop` |
|-----------|
| [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Innmind/IPC/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/IPC/?branch=develop) |
| [![Code Coverage](https://scrutinizer-ci.com/g/Innmind/IPC/badges/coverage.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/IPC/?branch=develop) |
| [![Build Status](https://scrutinizer-ci.com/g/Innmind/IPC/badges/build.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/IPC/build-status/develop) |

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
$ipc->listen(new Name('a'))(static function($message, $sender): void {
    echo "$sender says {$message->content()}";
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
$ipc->get($server)->send(new Name('b'))(
    new Message(MediaType::fromString('text/plain'), Str::of('hello world'))
);
```

The above example will result in the output `b says hello world` in the process `A`.
