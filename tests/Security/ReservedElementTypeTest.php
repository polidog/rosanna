<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\createElement;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** 文字列で関数名を受ける形を作らない。@see reservedTypeMarker */
function reservedTypeMarker(mixed $props = null): Element
{
    ReservedElementTypeTest::$invoked++;

    return H::span(children: 'ran');
}

/** RT-1.2 予約の type は data から作れない。RT-6.7 Element の props から文字列の関数名を実行しない */
final class ReservedElementTypeTest extends TestCase
{
    use RendersInScope;

    private const MARKER = __NAMESPACE__ . '\reservedTypeMarker';

    public static int $invoked = 0;

    protected function setUp(): void
    {
        self::$invoked = 0;
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public static function callMarker(): Element
    {
        return reservedTypeMarker();
    }

    /** @return iterable<string, array{string}> */
    public static function reservedTypes(): iterable
    {
        yield 'defer' => ['__defer__'];
    }

    /** @return array<string, mixed> */
    private static function attack(): array
    {
        return ['__component' => self::MARKER, '__props' => 'echo PWNED', '__key' => null, '__name' => 'x'];
    }

    #[DataProvider('reservedTypes')]
    public function testRT12CreateElementRefusesAReservedType(string $type): void
    {
        try {
            createElement($type, self::attack());
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($type, $e->getMessage());
        }
        self::assertSame(0, self::$invoked);
    }

    #[DataProvider('reservedTypes')]
    public function testRT12DynamicTagMethodRefusesAReservedType(string $type): void
    {
        try {
            H::{$type}(...self::attack());
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($type, $e->getMessage());
        }
        self::assertSame(0, self::$invoked);
    }

    /** RT-1.2 `__function_component__` は予約の type でなくなった。実行するものがなく、タグ名として拒否する */
    public function testRT12FunctionComponentIsRefusedAsAnInvalidTagName(): void
    {
        foreach ([createElement('__function_component__', self::attack()), H::__function_component__(...self::attack())] as $element) {
            try {
                self::html(static fn() => $element);
                self::fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Invalid HTML tag name "__function_component__"', $e->getMessage());
            }
        }
        self::assertSame(0, self::$invoked);
    }

    /** RT-1.2 生成コードが使うので Fragment は残す */
    public function testRT12FragmentStaysAvailableToGeneratedCode(): void
    {
        self::assertSame('a', self::html(static fn() => createElement('Fragment', ['children' => 'a'])));
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonClosures(): iterable
    {
        yield 'function name' => [self::MARKER];
        yield 'array callable' => [[self::class, 'callMarker']];
        yield 'invokable object' => [new class {
            public function __invoke(): Element
            {
                return ReservedElementTypeTest::callMarker();
            }
        }];
    }

    #[DataProvider('nonClosures')]
    public function testRT67HandBuiltElementDoesNotRunANonClosure(mixed $component): void
    {
        $element = new Element('__function_component__', ['__component' => $component, '__props' => [], '__key' => null]);

        try {
            self::html(static fn() => new Element('div', [], [H::div(children: [$element])]));
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            // RT-1.2 `__function_component__` は予約の type ではなくなり、タグ名の検査で落ちる
            self::assertStringContainsString('Invalid HTML tag name "__function_component__"', $e->getMessage());
        }
        self::assertSame(0, self::$invoked);
    }

    /** RT-6.7 Renderer で resolveRenderables を通る、手で組んだ木の経路 */
    public function testRT67HandBuiltElementAsADirectChildDoesNotRunAFunctionName(): void
    {
        $element = new Element('__function_component__', ['__component' => self::MARKER, '__props' => [], '__key' => null]);

        try {
            self::html(static fn() => new Element('div', [], [$element]));
        } catch (\RuntimeException) {
            // 予約でなくなった type はタグ名の検査で拒否される (RT-3.4)
        }
        self::assertSame(0, self::$invoked);
    }

    public function testRT67HandBuiltElementWithBadPropsIsARuntimeException(): void
    {
        $element = new Element('__function_component__', ['__component' => static fn() => H::div(), '__props' => 'echo PWNED']);

        $this->expectException(\RuntimeException::class);
        // 組み立てでは検査しなくなった。描画でタグ名の検査に落ち、props は読まれない
        $this->expectExceptionMessage('Invalid HTML tag name "__function_component__"');
        self::html(static fn() => H::div(children: [$element]));
    }
}
