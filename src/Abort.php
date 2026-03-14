<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Signals\{
    Signal,
    Info,
};

final class Abort
{
    private function __construct(
        private bool $value = false,
    ) {
    }

    public function __invoke(Signal $signal, Info $info): void
    {
        $this->value = true;
    }

    public static function disabled(): self
    {
        return new self;
    }

    public function enabled(): bool
    {
        return $this->value;
    }
}
