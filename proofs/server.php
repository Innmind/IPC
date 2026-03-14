<?php
declare(strict_types = 1);

use Innmind\IPC\{
    IPC,
    Process,
    Message,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Server\Control\Server\Command;
use Innmind\MediaType\{
    MediaType,
    TopLevel,
};
use Innmind\Url\Path;
use Innmind\Immutable\{
    Str,
    Monoid\Concat,
};

return static function() {
    $os = OperatingSystem::new();

    yield test(
        'Server wait for client',
        static function($assert) use ($os) {
            $process = $os
                ->control()
                ->processes()
                ->execute(
                    Command::foreground('sleep 2 && php fixtures/client.php')
                        ->withArgument('fixtures/client.php')
                        ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                        ->withEnvironment('PATH', $_SERVER['PATH'])
                        ->withWorkingDirectory(Path::of(__DIR__.'/../')),
                )
                ->unwrap();

            $output = IPC::of(
                $os,
                $os->status()->tmp()->resolve(Path::of('innnmind/ipc/')),
            )
                ->serve(Process\Name::of('server'))
                ->sink(Concat::monoid)
                ->monitor(
                    static fn($result, $continuation) => $continuation
                        ->carryWith($result)
                        ->finish(),
                )
                ->with(
                    static fn($message, $continuation) => $continuation
                        ->carryWith($message->content())
                        ->respond(Message::of(
                            MediaType::from(TopLevel::text, 'plain'),
                            Str::of('some output'),
                        ))
                        ->finish(),
                )
                ->match(
                    static fn($output) => $output->toString(),
                    static fn() => null,
                );

            $assert->same('hello world', $output);
            $assert->same(
                'some output',
                $process
                    ->output()
                    ->map(static fn($chunk) => $chunk->data())
                    ->fold(Concat::monoid)
                    ->toString(),
            );
        },
    );
};
