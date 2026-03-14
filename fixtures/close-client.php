<?php
declare(strict_types = 1);

require __DIR__.'/../vendor/autoload.php';

use Innmind\IPC\{
    IPC,
    Process,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\Path;

$os = OperatingSystem::new();
$_ = IPC::of(
    $os,
    $os->status()->tmp()->resolve(Path::of('innnmind/ipc/')),
)
    ->connectTo(Process\Name::of('server'))
    ->flatMap(static fn($process) => $process->close())
    ->unwrap();
