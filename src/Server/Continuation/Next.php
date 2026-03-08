<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\Continuation;

enum Next
{
    case continue;
    case finish;
}
