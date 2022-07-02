<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriod;
use Innmind\Immutable\{
    Maybe,
    SideEffect,
};

interface Process
{
    public function name(): Process\Name;

    /**
     * @no-named-arguments
     * @return Maybe<self> Returns nothing when messages can't be sent
     */
    public function send(Message ...$messages): Maybe;

    /**
     * @return Maybe<Message>
     */
    public function wait(ElapsedPeriod $timeout = null): Maybe;

    /**
     * @return Maybe<SideEffect> Returns nothing when couldn't close the connection properly
     */
    public function close(): Maybe;
    public function closed(): bool;
}
