<?php
declare(strict_types = 1);

use Innmind\IPC\{
    Factory as IPC,
    Process\Name,
};
use Innmind\OperatingSystem\Factory;
use Innmind\Immutable\Str;

require __DIR__.'/../vendor/autoload.php';

$os = Factory::build();
$ipc = IPC::build($os);
$ipc->listen(new Name('server'))(null, static function($message, $continuation) {
    if ($message->content()->equals(Str::of('stop'))) {
        return $continuation->stop();
    }

    return $continuation->respond($message);
});
