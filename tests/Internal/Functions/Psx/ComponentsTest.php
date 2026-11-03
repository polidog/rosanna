<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\returnedComponent;

use Polidog\Rosanna\Psx\NamespaceContext;

/** CP-132: manifest に書くコンポーネントを探す */
final class ComponentsTest extends TestCase
{
    /** @return iterable<string, array{string, class-string|null, list<string>}> */
    public static function components(): iterable
    {
        yield 'closure' => ['<?php return function (array $props) {};', Node\Expr\Closure::class, ['props']];
        yield 'arrow function' => ['<?php return fn($a, $b) => 1;', Node\Expr\ArrowFunction::class, ['a', 'b']];
        yield 'in a namespace' => ['<?php namespace App; return fn($x) => 1;', Node\Expr\ArrowFunction::class, ['x']];
        yield 'in a namespace block' => ['<?php namespace App { return fn($x) => 1; }', Node\Expr\ArrowFunction::class, ['x']];
        yield 'first return wins' => ['<?php return fn($a) => 1; return fn($b) => 2;', Node\Expr\ArrowFunction::class, ['a']];
        yield 'fc positional' => ['<?php use function Polidog\Rosanna\fc; return fc(fn($p) => 1);', Node\Expr\ArrowFunction::class, ['p']];
        yield 'fc named' => ['<?php return fc(key: "k", component: fn($p) => 1);', Node\Expr\ArrowFunction::class, ['p']];
        yield 'deferred qualified' => ['<?php return \Polidog\RosannaDefer\deferred(fn($p) => 1);', Node\Expr\ArrowFunction::class, ['p']];
        yield 'fc upper case' => ['<?php return FC(fn($p) => 1);', Node\Expr\ArrowFunction::class, ['p']];
        yield 'aliased fc' => ['<?php use function Polidog\Rosanna\fc as component; return component(fn($p) => 1);', Node\Expr\ArrowFunction::class, ['p']];
        yield 'other function' => ['<?php return wrap(fn($p) => 1);', null, []];
        yield 'not a closure' => ['<?php return 1;', null, []];
        yield 'no return' => ['<?php $a = fn() => 1;', null, []];
        yield 'return inside a function' => ['<?php function f() { return fn($a) => 1; }', null, []];
    }

    /**
     * @param class-string|null $class
     * @param list<string> $parameters
     */
    #[DataProvider('components')]
    public function testReturnedComponent(string $source, ?string $class, array $parameters): void
    {
        $component = returnedComponent($this->parse($source), NamespaceContext::fromSource($source), 'App\\C');

        if ($class === null) {
            self::assertNull($component);

            return;
        }
        self::assertInstanceOf($class, $component);
        self::assertSame($parameters, array_map(
            static fn(Node\Param $param): string => $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '',
            $component->params,
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableWrappers(): iterable
    {
        yield 'variable' => ['<?php $c = fn() => 1; return fc($c);'];
        yield 'no argument' => ['<?php return fc();'];
        yield 'named other argument' => ['<?php return deferred(key: "k");'];
        yield 'spread' => ['<?php return fc(...$args);'];
    }

    #[DataProvider('unreadableWrappers')]
    public function testWrapperWithoutAClosureIsAnError(string $source): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App\\C: the component passed to');

        returnedComponent($this->parse($source), NamespaceContext::fromSource($source), 'App\\C');
    }

    /** @return array<Node\Stmt> */
    private function parse(string $source): array
    {
        return new ParserFactory()->createForNewestSupportedVersion()->parse($source) ?? [];
    }
}
