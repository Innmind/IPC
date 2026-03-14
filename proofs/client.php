<?php
declare(strict_types = 1);

use Innmind\IPC\{
    IPC,
    Process,
    Message,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Server\Control\Server\{
    Command,
    Signal,
};
use Innmind\MediaType\{
    MediaType,
    TopLevel,
};
use Innmind\Url\Path;
use Innmind\Time\Period;
use Innmind\Immutable\{
    Str,
    Sequence,
    SideEffect,
};

return static function() {
    $os = OperatingSystem::new();
    $process = $os
        ->control()
        ->processes()
        ->execute(
            Command::foreground('php')
                ->withArgument('fixtures/server.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH'])
                ->withWorkingDirectory(Path::of(__DIR__.'/../')),
        )
        ->unwrap();
    // to make sure the server is started
    $_ = $process
        ->output()
        ->take(1)
        ->memoize()
        ->toList();
    \sleep(1);

    yield test(
        'Client wait timeout',
        static function($assert) use ($os) {
            $process = IPC::of(
                $os,
                $os->status()->tmp()->resolve(Path::of('innnmind/ipc/')),
            )
                ->connectTo(
                    Process\Name::of('server'),
                    Period::second(1),
                )
                ->unwrap();

            $assert->false($process->wait(Period::millisecond(500))->match(
                static fn() => true,
                static fn() => false,
            ));

            $assert->true($process->close()->match(
                static fn() => true,
                static fn() => false,
            ));
        },
    );

    yield test(
        'Client wait for message',
        static function($assert) use ($os) {
            $process = IPC::of(
                $os,
                $os->status()->tmp()->resolve(Path::of('innnmind/ipc/')),
            )
                ->connectTo(
                    Process\Name::of('server'),
                    Period::second(1),
                )
                ->unwrap();

            $assert->same(
                SideEffect::identity,
                $process
                    ->send(Sequence::of(Message::of(
                        MediaType::from(TopLevel::text, 'plain'),
                        Str::of('1'),
                    )))
                    ->match(
                        static fn($value) => $value,
                        static fn() => null,
                    ),
            );
            $assert->same(
                'ack from server : 1',
                $process
                    ->wait()
                    ->match(
                        static fn($message) => $message->content()->toString(),
                        static fn() => null,
                    ),
            );

            $assert->true($process->close()->match(
                static fn() => true,
                static fn() => false,
            ));
        },
    );

    $_ = $process->pid()->match(
        static fn($pid) => $os
            ->control()
            ->processes()
            ->kill($pid, Signal::kill)
            ->unwrap(),
        static fn() => null,
    );
};
