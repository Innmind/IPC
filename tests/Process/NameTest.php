<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC;

use Innmind\IPC\{
    Process\Name,
    Exception\DomainException,
};
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set,
};

class NameTest extends TestCase
{
    use BlackBox;

    public function testInterface()
    {
        $this
            ->forAll(Set\Elements::of(
                'fooBar',
                'foo_bar',
                'foo-bar',
                '42foo',
            ))
            ->then(function(string $string): void {
                $this->assertSame($string, Name::of($string)->toString());
            });
    }

    public function testThrowWhenContainsInvalidCharacter()
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('foo.bar');

        Name::of('foo.bar');
    }
}
