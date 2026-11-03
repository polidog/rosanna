<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Runtime\assertValidAttributeName;
use function Polidog\Rosanna\Internal\Functions\Runtime\firstProp;
use function Polidog\Rosanna\Internal\Functions\Runtime\isBooleanStringAttribute;
use function Polidog\Rosanna\Internal\Functions\Runtime\isUrlAttribute;
use function Polidog\Rosanna\Internal\Functions\Runtime\targetsHref;

use Polidog\Rosanna\Runtime\AttributeNames;

/** RT-3.7、RT-3.8、RT-3.9、RT-3.11 */
final class AttributesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function badAttributeNames(): iterable
    {
        yield 'quote and space' => ['a" onclick="x'];
        yield 'angle' => ['a>'];
        yield 'whitespace' => ['a b'];
        yield 'equals' => ['a=b'];
        yield 'slash' => ['a/b'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["a\n"];
        yield 'leading digit' => ['1a'];
    }

    #[DataProvider('badAttributeNames')]
    public function testInvalidAttributeNameThrows(string $name): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name');
        assertValidAttributeName($name);
    }

    /** @return iterable<string, array{string}> */
    public static function inlineHandlerNames(): iterable
    {
        yield 'lower' => ['onclick'];
        yield 'error' => ['onerror'];
        yield 'camel' => ['onMouseOver'];
        yield 'upper' => ['ONCLICK'];
    }

    #[DataProvider('inlineHandlerNames')]
    public function testInlineHandlerNameThrows(string $name): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler');
        assertValidAttributeName($name);
    }

    public function testValidNamesPass(): void
    {
        $this->expectNotToPerformAssertions();
        foreach (['data-x', 'aria-label', 'xml:lang', 'viewBox', '_x', ':x', 'on', ...array_values(AttributeNames::MAP)] as $name) {
            assertValidAttributeName($name);
        }
    }

    public function testUrlAttributes(): void
    {
        foreach (['href', 'src', 'action', 'formaction', 'data', 'poster', 'background', 'xlink:href'] as $name) {
            self::assertTrue(isUrlAttribute($name), $name);
        }
        self::assertFalse(isUrlAttribute('title'));
        self::assertFalse(isUrlAttribute('data-href'));
    }

    public function testBooleanStringAttributes(): void
    {
        foreach (['aria-hidden', 'aria-busy', 'contenteditable', 'draggable', 'spellcheck'] as $name) {
            self::assertTrue(isBooleanStringAttribute($name), $name);
        }
        self::assertFalse(isBooleanStringAttribute('disabled'));
        self::assertFalse(isBooleanStringAttribute('aria'));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function animationTargets(): iterable
    {
        yield 'href' => [['attributeName' => 'href'], true];
        yield 'upper' => [['attributeName' => 'HREF'], true];
        yield 'prefixed' => [['attributeName' => 'xlink:href'], true];
        yield 'any prefix' => [['attributeName' => 'foo:href'], true];
        yield 'padded' => [['attributeName' => " href\x00"], true];
        yield 'mapped key' => [['ATTRIBUTENAME' => 'href'], true];
        yield 'other attribute' => [['attributeName' => 'fill'], false];
        yield 'non scalar' => [['attributeName' => ['href']], false];
        yield 'no attributeName' => [['to' => 'href'], false];
    }

    /** @param array<string, mixed> $props */
    #[DataProvider('animationTargets')]
    public function testTargetsHref(array $props, bool $expected): void
    {
        self::assertSame($expected, targetsHref($props));
    }

    public function testFirstPropIsCaseInsensitiveAndSkipsNull(): void
    {
        self::assertSame('a', firstProp(['Value' => 'a', 'value' => 'b'], 'value'));
        self::assertSame('b', firstProp(['VALUE' => null, 'value' => 'b'], 'value'));
        self::assertNull(firstProp(['other' => 'x'], 'value'));
    }
}
