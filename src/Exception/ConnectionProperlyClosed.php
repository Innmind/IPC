<?php
declare(strict_types = 1);

namespace Innmind\IPC\Exception;

use Innmind\Immutable\SideEffect;

/**
 * @internal
 * @template T
 */
final class ConnectionProperlyClosed extends \Exception
{
    /**
     * @param T $value
     */
    public function __construct(
        private mixed $value = SideEffect::identity,
    ) {
        parent::__construct();
    }

    /**
     * @template U
     *
     * @param U $value
     *
     * @return self<U>
     */
    public function with(mixed $value): self
    {
        return new self($value);
    }

    /**
     * @return T
     */
    public function unwrap(): mixed
    {
        return $this->value;
    }
}
