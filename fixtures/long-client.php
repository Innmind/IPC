<?php
declare(strict_types = 1);

use Innmind\IPC\{
    Factory as IPC,
    Message,
    Process\Name,
    Exception\ConnectionClosed,
};
use Innmind\OperatingSystem\Factory;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = IPC::build($os);
$ipc->wait(new Name('server'));
$process = $ipc->get(new Name('server'));
$process->send(new Message\Generic(
    MediaType::of('text/plain'),
    Str::of('hello world')
));

try {
    $process->wait();
} catch (ConnectionClosed $e) {
}
