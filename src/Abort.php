<?php
declare(strict_types = 1);

namespace Innmind\IPC;

final class Abort
{
    private function __construct(
        private bool $value = false,
    ) {
    }

    public function __invoke(): bool
    {
        return $this->value;
    }

    public static function disabled(): self
    {
        return new self;
    }

    public function enable(): void
    {
        $this->value = true;
    }
}
