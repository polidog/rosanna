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

/** RT-3.4、RT-3.5、RT-3.12、RT-3.14 */
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
}
