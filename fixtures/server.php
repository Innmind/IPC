<?php
declare(strict_types = 1);

use function Innmind\IPC\bootstrap;
use Innmind\IPC\{
    Process\Name,
    Exception\Stop,
};
use Innmind\OperatingSystem\Factory;

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = bootstrap($os);
$ipc->listen(new Name('server'))(static function($message, $client): void {
    $client->send($message);
    $client->close();
    throw new Stop;
});
