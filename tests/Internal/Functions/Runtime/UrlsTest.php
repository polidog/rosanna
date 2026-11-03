<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Runtime\hasDangerousAnimationValue;
use function Polidog\Rosanna\Internal\Functions\Runtime\hasDangerousScheme;

/** RT-3.11 */
final class UrlsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function dangerousUrls(): iterable
    {
        yield 'plain' => ['javascript:alert(1)'];
        yield 'upper' => ['JAVASCRIPT:alert(1)'];
        yield 'leading tab' => ["\tjavascript:alert(1)"];
        yield 'inner tab' => ["java\tscript:alert(1)"];
        yield 'inner newline' => ["java\nscript:alert(1)"];
        yield 'inner cr' => ["java\rscript:alert(1)"];
        yield 'leading space' => ['   javascript:alert(1)'];
        yield 'leading control' => ["\x01\x1Fjavascript:alert(1)"];
        yield 'leading nul' => ["\x00javascript:alert(1)"];
        yield 'vbscript' => ['VbScript:x'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'tab before colon' => ["javascript\t:alert(1)"];
    }

    #[DataProvider('dangerousUrls')]
    public function testDangerousSchemes(string $url): void
    {
        self::assertTrue(hasDangerousScheme($url));
    }

    /** @return iterable<string, array{string}> */
    public static function harmlessUrls(): iterable
    {
        yield 'https' => ['https://example.com/?a=1&b=2'];
        yield 'relative' => ['/path:with/colon'];
        yield 'path colon' => ['foo/bar:baz'];
        yield 'query colon' => ['?javascript:alert(1)'];
        yield 'fragment' => ['#javascript:x'];
        yield 'bad scheme' => ['java script:alert(1)'];
        yield 'leading colon' => [':javascript'];
        yield 'mailto' => ['mailto:a@example.com'];
        yield 'empty' => [''];
        yield 'tab is kept' => ["/a\tb"];
    }

    #[DataProvider('harmlessUrls')]
    public function testHarmlessUrls(string $url): void
    {
        self::assertFalse(hasDangerousScheme($url));
    }

    public function testAnimationValuesAreCheckedPerSemicolonPart(): void
    {
        self::assertTrue(hasDangerousAnimationValue('javascript:x'));
        self::assertTrue(hasDangerousAnimationValue('/a; javascript:x'));
        self::assertTrue(hasDangerousAnimationValue("/a;\tjavascript:x"));
        self::assertFalse(hasDangerousAnimationValue('/a;/b'));
        self::assertFalse(hasDangerousAnimationValue(''));
    }
}
