<?php
declare(strict_types = 1);

namespace Innmind\IPC\Continuation;

enum Next
{
    case continue;
    case finish;
}
