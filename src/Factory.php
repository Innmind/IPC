<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Url\Path;
use Innmind\TimeContinuum\Earth\ElapsedPeriod;

final class Factory
{
    public static function build(
        OperatingSystem $os,
        Path $sockets = null,
        ElapsedPeriod $heartbeat = null,
    ): IPC {
        $sockets ??= $os->status()->tmp()->resolve(Path::of('innmind/ipc/'));
        $heartbeat ??= new ElapsedPeriod(1000); // default to 1 second

        return new IPC\Unix(
            $os->sockets(),
            $os->filesystem()->mount($sockets),
            $os->clock(),
            $os->process(),
            new Protocol\Binary,
            $sockets,
            $heartbeat,
        );
    }
}
