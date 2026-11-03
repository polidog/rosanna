<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Runtime;

use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Runtime\collapseWhitespace;
use function Polidog\Rosanna\Internal\Functions\Runtime\escape;
use function Polidog\Rosanna\Internal\Functions\Runtime\joinRawText;
use function Polidog\Rosanna\Internal\Functions\Runtime\quote;
use function Polidog\Rosanna\Internal\Functions\Runtime\textOf;

use Polidog\Rosanna\Runtime\Element;

final class TextTest extends TestCase
{
    public function testEscapeKeepsTheRestOfInvalidUtf8(): void
    {
        self::assertSame('&lt;a href=&quot;x&quot; title=&#039;y&#039;&gt;&amp;', escape('<a href="x" title=\'y\'>&'));
        self::assertSame("a\u{FFFD}b", escape("a\xFFb"));
    }

    public function testQuote(): void
    {
        self::assertSame('"a/b"', quote('a/b'));
        self::assertSame('"あ"', quote('あ'));
        self::assertSame("\"\u{FFFD}\"", quote("\xFF"));
    }

    public function testCollapseWhitespace(): void
    {
        self::assertSame('Plan B', collapseWhitespace(" \n Plan \t  B\n"));
        self::assertSame("a\u{A0}b", collapseWhitespace("a\u{A0}b"));
    }

    public function testTextOfJoinsDescendants(): void
    {
        self::assertSame('a1b2.5', textOf(['a', 1, new Element('b', [], ['b', null, true]), 2.5, ['ignored']]));
    }

    public function testJoinRawTextFlattensArrays(): void
    {
        self::assertSame('a<b1', joinRawText([['a', ['<b']], 1, null, false]));
    }

    public function testJoinRawTextRefusesElements(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot render a child of type Polidog\Rosanna\Runtime\Element inside <script>');
        joinRawText([new Element('b')]);
    }
}
