<?php
declare(strict_types = 1);

use function Innmind\IPC\bootstrap;
use Innmind\IPC\{
    Message,
    Process\Name,
    Exception\Stop,
};
use Innmind\OperatingSystem\Factory;
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = bootstrap($os);
$ipc->wait(new Name('server'));
$ipc->get(new Name('server'))->send(new Name('client'))(new Message\Generic(
    MediaType::fromString('text/plain'),
    Str::of('hello world')
));
$ipc->listen(new Name('client'))(static function($message, $sender): void {
    echo "{$message->content()} from $sender\n";
    throw new Stop;
});
