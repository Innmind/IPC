<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\Client;

/**
 * @internal
 */
final class Stop extends \Exception
{
    public function __construct(private mixed $value)
    {
    }

    public function unwrap(): mixed
    {
        return $this->value;
    }
}
