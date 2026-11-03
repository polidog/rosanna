<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Runtime\assertValidTagName;
use function Polidog\Rosanna\Internal\Functions\Runtime\isForeignContentRoot;
use function Polidog\Rosanna\Internal\Functions\Runtime\isRawTextTag;
use function Polidog\Rosanna\Internal\Functions\Runtime\isTextOnlyAncestor;
use function Polidog\Rosanna\Internal\Functions\Runtime\isVoidTag;
use function Polidog\Rosanna\Internal\Functions\Runtime\resolveDeep;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

/** RT-3.4、RT-3.5、RT-3.12、RT-3.14、RT-3.17 */
final class ElementsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function badTags(): iterable
    {
        yield 'space' => ['div onclick=x'];
        yield 'angle' => ['a><script>'];
        yield 'empty' => [''];
        yield 'digit' => ['1a'];
        yield 'newline' => ["div\n"];
        yield 'underscore' => ['__function_component__'];
        yield 'colon' => ['svg:rect'];
    }

    #[DataProvider('badTags')]
    public function testInvalidTagNameThrows(string $tag): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML tag name');
        assertValidTagName($tag);
    }

    public function testValidTagNamesPass(): void
    {
        $this->expectNotToPerformAssertions();
        foreach (['div', 'H1', 'linearGradient', 'my-widget', 'x'] as $tag) {
            assertValidTagName($tag);
        }
    }

    public function testTagKinds(): void
    {
        foreach (['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'] as $tag) {
            self::assertTrue(isVoidTag($tag), $tag);
        }
        self::assertFalse(isVoidTag('div'));

        self::assertTrue(isRawTextTag('script'));
        self::assertTrue(isRawTextTag('style'));
        self::assertFalse(isRawTextTag('textarea'));

        foreach (['noscript', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes'] as $tag) {
            self::assertTrue(isTextOnlyAncestor($tag), $tag);
        }
        self::assertFalse(isTextOnlyAncestor('script'));

        self::assertTrue(isForeignContentRoot('svg'));
        self::assertTrue(isForeignContentRoot('math'));
        self::assertFalse(isForeignContentRoot('div'));
    }

    public function testResolveDeepResolvesDescendantsOnce(): void
    {
        $count = 0;
        $renderable = new class ($count) implements Renderable {
            public function __construct(private int &$count) {}

            public function render(): Element
            {
                $this->count++;

                return new Element('i', [], ['x']);
            }
        };

        $resolved = resolveDeep(['a', [1, null], new Element('b', [], [[$renderable], new Element('c', [], [$renderable])])]);

        self::assertEquals(
            ['a', 1, null, new Element('b', [], [new Element('i', [], ['x']), new Element('c', [], [new Element('i', [], ['x'])])])],
            $resolved,
        );
        self::assertSame(2, $count);
    }
}
