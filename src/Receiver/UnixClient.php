<?php
declare(strict_types = 1);

namespace Innmind\IPC\Receiver;

use Innmind\IPC\{
    Receiver,
    Protocol,
    Process,
    Exception\NoMessage,
    Exception\Stop,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Exception\Exception as Socket,
};
use Innmind\Stream\{
    Select,
    Exception\Exception as Stream,
};
use Innmind\TimeContinuum\{
    ElapsedPeriodInterface,
    ElapsedPeriod,
};

final class UnixClient implements Receiver
{
    private $sockets;
    private $protocol;
    private $processName;
    private $address;
    private $selectTimeout;
    private $timeout;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        Process\Name $processName,
        Address $address,
        ElapsedPeriod $selectTimeout,
        ElapsedPeriodInterface $timeout = null
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->processName = $processName;
        $this->address = $address;
        $this->selectTimeout = $selectTimeout;
        $this->timeout = $timeout;
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
        } catch (Stop $e) {
            // do nothing when user want to stop listening
        } catch (Stream | Socket $e) {
            throw new RuntimeException('', 0, $e);
        }
    }
    private function loop(callable $listen): void
    {
        $client = $this->sockets->connectTo($this->address);
        $select = (new Select($this->selectTimeout))->forRead($client);

        do {
            $sockets = $select();

            try {
                if ($sockets->get('read')->contains($client)) {
                    $message = $this->protocol->decode($client);

                    $listen($message, $this->processName);
                }
            } catch (\Throwable $e) {
                $client->close();

                throw $e;
            }

        } while (true);
    }
}
