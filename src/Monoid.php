<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Immutable\{
    Monoid as Monoid_,
    SideEffect,
};

/**
 * @internal
 * @psalm-immutable
 * @implements Monoid_<SideEffect>
 */
enum Monoid implements Monoid_
{
    case sideEffect;

    #[\Override]
    public function identity(): SideEffect
    {
        return SideEffect::identity;
    }

    #[\Override]
    public function combine(mixed $a, mixed $b): SideEffect
    {
        return SideEffect::identity;
    }
}
