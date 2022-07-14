<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriod;
use Innmind\Immutable\{
    Maybe,
    SideEffect,
    Sequence,
};

interface Process
{
    public function name(): Process\Name;

    /**
     * @param Sequence<Message> $messages
     *
     * @return Maybe<self> Returns nothing when messages can't be sent
     */
    public function send(Sequence $messages): Maybe;

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
