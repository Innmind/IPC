<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\{
    PathInterface,
    Path,
};

function bootstrap(OperatingSystem $os, PathInterface $sockets = null): IPC
{
    $sockets = $sockets ?? new Path("{$os->status()->tmp()}/innmind/ipc");

    return new IPC\Unix(
        $os->sockets(),
        $os->filesystem()->mount($sockets),
        new Protocol\Binary,
        $sockets
    );
}
