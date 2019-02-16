<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\{
    PathInterface,
    Path,
};
use Innmind\TimeContinuum\ElapsedPeriod;

function bootstrap(
    OperatingSystem $os,
    PathInterface $sockets = null,
    ElapsedPeriod $selectTimeout = null
): IPC {
    $sockets = $sockets ?? new Path("{$os->status()->tmp()}/innmind/ipc");
    $selectTimeout = $selectTimeout ?? new ElapsedPeriod(1000); // default to 1 second

    return new IPC\Unix(
        $os->sockets(),
        $os->filesystem()->mount($sockets),
        $os->clock(),
        $os->process(),
        new Protocol\Binary,
        $sockets,
        $selectTimeout
    );
}
