<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\PathInterface;

function bootstrap(OperatingSystem $os, PathInterface $sockets = null): IPC
{
    $sockets = $sockets ?? $os->status()->tmp();

    return new IPC\Unix(
        $os->sockets(),
        $os->filesystem()->mount($sockets),
        new Protocol\Binary,
        $sockets
    );
}
