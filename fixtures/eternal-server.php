<?php
declare(strict_types = 1);

use Innmind\IPC\{
    Factory as IPC,
    Process\Name,
};
use Innmind\OperatingSystem\Factory;

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = IPC::build($os);
$ipc->listen(new Name('server'))(static function($message, $continuation) {
    return $continuation;
});
