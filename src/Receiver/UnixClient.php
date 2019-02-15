<?php
declare(strict_types = 1);

namespace Innmind\IPC\Receiver;

use Innmind\IPC\{
    Receiver,
    Protocol,
    Process,
    Exception\NoMessage,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\Address\Unix as Address;
use Innmind\Stream\Select;
use Innmind\TimeContinuum\{
    ElapsedPeriodInterface,
    ElapsedPeriod,
};

final class UnixClient implements Receiver
{
    private $sockets;
    private $protocol;
    private $process;
    private $address;
    private $timeout;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        Process $process,
        Address $address,
        ElapsedPeriodInterface $timeout = null
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->process = $process;
        $this->address = $address;
        $this->timeout = new ElapsedPeriod(
            ($timeout ?? new ElapsedPeriod(60000))->milliseconds() // default to 1 minute
        );
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(callable $listen): void
    {
        try {
            $this->loop($listen);
        } catch (NoMessage $e) {
            // the other process closed the connection
            return;
        }
    }
    private function loop(callable $listen): void
    {
        $client = $this->sockets->connectTo($this->address);
        $select = (new Select($this->timeout))->forRead($client);

        do {
            $sockets = $select();

            try {
                if ($sockets->get('read')->contains($client)) {
                    $message = $this->protocol->decode($client);

                    $listen($message, $this->process);
                }
            } catch (\Throwable $e) {
                $client->close();

                throw $e;
            }

        } while (true);
    }
}
