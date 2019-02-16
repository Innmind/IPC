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
$messageToSend = null;
$sender = null;
$ipc->listen(new Name('server'))(static function($message, $process) use (&$messageToSend, &$sender): void {
    $messageToSend = $message;
    $sender = $process;
    throw new Stop;
});
$ipc->wait($sender);
$ipc->get($sender)->send(new Name('server'))($messageToSend);
