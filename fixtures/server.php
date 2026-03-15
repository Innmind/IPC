<?php
declare(strict_types = 1);

require __DIR__.'/../vendor/autoload.php';

use Innmind\IPC\{
    IPC,
    Process,
    Message,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\Path;
use Innmind\MediaType\{
    MediaType,
    TopLevel,
};

echo 'starting';
$os = OperatingSystem::new();
$_ = IPC::of(
    $os,
    $os->status()->tmp()->resolve(Path::of('innnmind/ipc/')),
)
    ->serve(Process\Name::of('server'))
    ->with(static function($message, $continuation) {
        return $continuation->respond(Message::of(
            MediaType::from(TopLevel::text, 'plain'),
            $message->content()->prepend('ack from server : '),
        ));
    })
    ->unwrap();
