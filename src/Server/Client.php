<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Protocol,
    Message,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Signals\Signal;
use Innmind\IO\Sockets\Clients\Client as Socket;
use Innmind\Immutable\{
    Sequence,
    Attempt,
    SideEffect,
};

final class Client
{
    private function __construct(
        private Socket $client,
        private Protocol $protocol,
    ) {
    }

    /**
     * @return Attempt<SideEffect>
     */
    public function __invoke(OperatingSystem $os): Attempt
    {
        $abort = false;

        $signaled = $os
            ->process()
            ->signals()
            ->listen(Signal::terminate, static function() use (&$abort) {
                $abort = true;
            });
        $frame = $this->protocol->frame();
        // unwrapping is safe as it's internal messages
        $heartbeat = $this->protocol->encode(Message::heartbeat())->unwrap();
        $ack = $this->protocol->encode(Message::ack())->unwrap();

        // Use an infinite sequence to iteractively wait for a message to arrive
        // If the received one is a heartbeat we return a side effect, meaning
        // we restart the loop. Otherwise we send an acknowledgement to the
        // client before handling the message.
        // Since we heartbeat when waiting for a message the ->one() call will
        // always return a message unless there's a network error. This means
        // that by default ->one() will wait forever.
        // And since we use the sink pattern on the sequence, everything will
        // stop as soon any part of the system returns an error.
        return Sequence::lazy(static function() use ($signaled) {
            yield $signaled;

            while (true) {
                yield SideEffect::identity;
            }
        })
            ->sink(SideEffect::identity)
            ->attempt(
                fn($_, $val) => match (true) {
                    $val instanceof Attempt => $val,
                    default => $this
                        ->client
                        ->heartbeatWith(static fn() => Sequence::of($heartbeat))
                        ->abortWhen(static function() use (&$abort) {
                            return $abort;
                        })
                        ->frames($frame)
                        ->one()
                        ->flatMap(
                            fn($message) => match ($message->equals(Message::heartbeat())) {
                                true => Attempt::result(SideEffect::identity),
                                false => $this
                                    ->client
                                    ->sink(Sequence::of($ack))
                                    ->flatMap(fn() => $this->handle($message)),
                            },
                        ),
                },
            )
            ->recover(
                fn($e) => $this
                    ->client
                    ->close()
                    ->match( // make sure to return the original error
                        static fn() => Attempt::error($e),
                        static fn() => Attempt::error($e),
                    ),
            );
    }

    public static function of(
        Socket $client,
        Protocol $protocol,
    ): self {
        return new self($client, $protocol);
    }

    /**
     * @return Attempt<SideEffect>
     */
    private function handle(Message $message): Attempt
    {
        return Attempt::result(SideEffect::identity); // todo
    }
}
