<?php
declare(strict_types = 1);

use function Innmind\IPC\bootstrap;
use Innmind\IPC\{
    Message,
    Process\Name,
    Exception\ConnectionClosed,
};
use Innmind\OperatingSystem\Factory;
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = bootstrap($os);
$ipc->wait(new Name('server'));
$process = $ipc->get(new Name('server'));
$process->send(new Message\Generic(
    MediaType::fromString('text/plain'),
    Str::of('hello world')
));

try {
    $process->wait();
} catch (ConnectionClosed $e) {
}
