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
echo 'Server ready!';
$ipc->listen(Name::of('server'))(null, static function($message, $continuation) {
    return $continuation;
});
