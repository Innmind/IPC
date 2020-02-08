<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC;

use Innmind\IPC\{
    Process\Name,
    Exception\DomainException,
};
use PHPUnit\Framework\TestCase;
use Eris\{
    Generator,
    TestTrait,
};

class NameTest extends TestCase
{
    use TestTrait;

    public function testInterface()
    {
        $this
            ->forAll(Generator\elements(
                'fooBar',
                'foo_bar',
                'foo-bar',
                '42foo'
            ))
            ->then(function(string $string): void {
                $this->assertSame($string, (new Name($string))->toString());
            });
    }

    public function testThrowWhenContainsInvalidCharacter()
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('foo.bar');

        new Name('foo.bar');
    }
}
