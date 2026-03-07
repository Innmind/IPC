<?php
declare(strict_types = 1);

namespace Innmind\IPC\Process;

use Innmind\Immutable\{
    Str,
    Attempt,
};

final class Name
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(private string $value)
    {
    }

    /**
     * @param literal-string $value
     *
     * @throws \DomainException
     */
    public static function of(string $value): self
    {
        return self::attempt($value)->unwrap();
    }

    /**
     * @return Attempt<self>
     */
    public static function attempt(string $value): Attempt
    {
        if (!Str::of($value)->matches('~^[a-zA-Z0-9-_]+$~')) {
            return Attempt::error(new \DomainException($value));
        }

        /** @psalm-suppress ArgumentTypeCoercion */
        return Attempt::result(new self($value));
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->value;
    }
}
