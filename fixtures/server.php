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
$ipc->listen(Name::of('server'))(null, static function($message, $continuation, $carry) {
    if ($message->content()->equals(Str::of('stop'))) {
        return $continuation->stop($carry);
    }

    return $continuation->respond($carry, $message);
});
