<?php
declare(strict_types = 1);

namespace Innmind\IPC\Process;

use Innmind\IPC\Exception\DomainException;
use Innmind\Immutable\{
    Str,
    Maybe,
};

final class Name
{
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @param literal-string $value
     *
     * @throws DomainException
     */
    public static function of(string $value): self
    {
        return self::maybe($value)->match(
            static fn($self) => $self,
            static fn() => throw new DomainException($value),
        );
    }

    /**
     * @return Maybe<self>
     */
    public static function maybe(string $value): Maybe
    {
        return Maybe::just($value)
            ->map(Str::of(...))
            ->filter(static fn($value) => $value->matches('~^[a-zA-Z0-9-_]+$~'))
            ->map(static fn($value) => new self($value->toString()));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
