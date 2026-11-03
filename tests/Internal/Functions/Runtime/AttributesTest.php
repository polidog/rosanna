<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Runtime\assertValidAttributeName;
use function Polidog\Rosanna\Internal\Functions\Runtime\attributeName;
use function Polidog\Rosanna\Internal\Functions\Runtime\firstProp;
use function Polidog\Rosanna\Internal\Functions\Runtime\isBooleanStringAttribute;
use function Polidog\Rosanna\Internal\Functions\Runtime\isUrlAttribute;
use function Polidog\Rosanna\Internal\Functions\Runtime\renderAttributes;
use function Polidog\Rosanna\Internal\Functions\Runtime\selectValues;
use function Polidog\Rosanna\Internal\Functions\Runtime\targetsHref;

use Polidog\Rosanna\Runtime\AttributeNames;

/** RT-3.7、RT-3.8、RT-3.9、RT-3.10、RT-3.11、RT-3.17 */
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

    /** 互換のために残した AttributeNames::MAP と同じ対応を持つ */
    public function testAttributeNameAgreesWithTheDeprecatedMap(): void
    {
        foreach (AttributeNames::MAP as $jsx => $html) {
            self::assertSame($html, attributeName($jsx), $jsx);
            // 変換した名前をもう一度通しても変わらない
            self::assertSame($html, attributeName($html), $html);
        }
    }

    public function testAttributeNameKeepsNamesNotInTheTable(): void
    {
        foreach (['class', 'data-x', 'aria-label', 'viewBox', 'unknownName', 'classname', 'CLASSNAME', ''] as $name) {
            self::assertSame($name, attributeName($name), $name);
        }
    }

    /** @return iterable<string, array{mixed, list<string>}> */
    public static function selectValueCases(): iterable
    {
        yield 'string' => ['b', ['b']];
        yield 'empty string' => ['', ['']];
        yield 'int' => [1, ['1']];
        yield 'zero' => [0, ['0']];
        yield 'float' => [1.5, ['1.5']];
        yield 'null' => [null, []];
        yield 'true' => [true, []];
        yield 'false' => [false, []];
        yield 'array' => [[1, 'b'], ['1', 'b']];
        yield 'empty array' => [[], []];
        yield 'duplicates' => [['1', 1], ['1']];
        yield 'null and bool in array' => [['a', null, true, false], ['a']];
    }

    /** @param list<string> $expected */
    #[DataProvider('selectValueCases')]
    public function testSelectValues(mixed $value, array $expected): void
    {
        self::assertSame($expected, array_map(strval(...), array_keys(selectValues($value))));
    }

    public function testSelectValuesDropsNonScalarValuesWithANotice(): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = [$level, $message];

            return true;
        });
        try {
            $single = selectValues(new \stdClass());
            $inArray = selectValues(['a', ['b'], new \stdClass()]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $single);
        self::assertSame(['a' => true], $inArray);
        self::assertSame([
            [E_USER_NOTICE, 'Non-scalar value for attribute "value" (stdClass) was dropped.'],
            [E_USER_NOTICE, 'Non-scalar value for attribute "value" (array) was dropped.'],
            [E_USER_NOTICE, 'Non-scalar value for attribute "value" (stdClass) was dropped.'],
        ], $notices);
    }

    /** @return iterable<string, array{string, array<array-key, mixed>, string}> */
    public static function renderAttributesCases(): iterable
    {
        yield 'no props' => ['div', [], ''];
        yield 'jsx name' => ['label', ['htmlFor' => 'a', 'className' => 'b'], ' for="a" class="b"'];
        yield 'escaped value' => ['div', ['title' => '"<&>\''], ' title="&quot;&lt;&amp;&gt;&#039;"'];
        yield 'int and float' => ['input', ['maxLength' => 3, 'step' => 0.5], ' maxlength="3" step="0.5"'];
        yield 'true is a bare attribute' => ['input', ['disabled' => true], ' disabled'];
        yield 'false is dropped' => ['input', ['disabled' => false], ''];
        yield 'aria bool' => ['div', ['aria-hidden' => true, 'aria-busy' => false], ' aria-hidden="true" aria-busy="false"'];
        yield 'draggable bool' => ['div', ['draggable' => false], ' draggable="false"'];
        yield 'dangerous url' => ['a', ['href' => 'javascript:alert(1)', 'id' => 'x'], ' id="x"'];
        yield 'upper-case url attribute' => ['a', ['HREF' => 'JavaScript:alert(1)'], ''];
        yield 'safe url' => ['a', ['href' => '/path?a=1&b=2'], ' href="/path?a=1&amp;b=2"'];
        yield 'url check only on url attributes' => ['div', ['title' => 'javascript:x'], ' title="javascript:x"'];
        yield 'empty srcdoc' => ['iframe', ['srcdoc' => ''], ' srcdoc=""'];
        yield 'set targeting href' => ['set', ['attributeName' => 'href', 'to' => 'javascript:x'], ' attributeName="href"'];
        yield 'set targeting other' => ['set', ['attributeName' => 'fill', 'to' => 'javascript:x'], ' attributeName="fill" to="javascript:x"'];
        yield 'animate values' => ['animate', ['attributeName' => 'xlink:href', 'values' => '/a;javascript:x'], ' attributeName="xlink:href"'];
        yield 'not an animation tag' => ['div', ['attributeName' => 'href', 'to' => 'javascript:x'], ' attributeName="href" to="javascript:x"'];
    }

    /** @param array<array-key, mixed> $props */
    #[DataProvider('renderAttributesCases')]
    public function testRenderAttributes(string $lowerTag, array $props, string $expected): void
    {
        self::assertSame($expected, renderAttributes($lowerTag, $props));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function rejectedAttributes(): iterable
    {
        yield 'event handler string' => [['onclick' => 'x'], 'Inline event handler attribute'];
        yield 'event handler bool' => [['onClick' => true], 'Inline event handler attribute'];
        yield 'event handler null value is still a name' => [['onload' => null], 'Inline event handler attribute'];
        yield 'srcdoc with value' => [['srcdoc' => '<b>x</b>'], 'The srcdoc attribute is not supported'];
        yield 'bad name' => [['a b' => 'x'], 'Invalid HTML attribute name'];
    }

    /** @param array<array-key, mixed> $props */
    #[DataProvider('rejectedAttributes')]
    public function testRenderAttributesRejects(array $props, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        renderAttributes('div', $props);
    }

    public function testRenderAttributesDropsNonScalarValuesWithANotice(): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = [$level, $message];

            return true;
        });
        try {
            $html = renderAttributes('div', ['className' => ['a'], 'id' => 'x']);
        } finally {
            restore_error_handler();
        }

        self::assertSame(' id="x"', $html);
        self::assertSame([[E_USER_NOTICE, 'Non-scalar value for attribute "class" (array) was dropped.']], $notices);
    }
}
