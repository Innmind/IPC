<?php
declare(strict_types = 1);

namespace Innmind\IPC\Continuation;

/**
 * @internal
 * @psalm-immutable
 */
enum Next
{
    case continue;
    case finish;
}
