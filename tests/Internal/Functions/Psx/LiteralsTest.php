<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\decodeEntities;
use function Polidog\Rosanna\Internal\Functions\Psx\normalizeText;
use function Polidog\Rosanna\Internal\Functions\Psx\singleLineStringLiteral;
use function Polidog\Rosanna\Internal\Functions\Psx\stringLiteral;

/** CP-40、CP-41、CP-50、CP-75、CP-102 */
final class LiteralsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function strings(): iterable
    {
        yield 'plain' => ['abc'];
        yield 'quote' => ["it's"];
        yield 'backslash' => ['a\\b'];
        yield 'backslash before quote' => ["a\\'b"];
        yield 'trailing backslash' => ['a\\'];
        yield 'dollar' => ['$a {$b}'];
        yield 'php tags' => ['?><?php exit;'];
        yield 'newline' => ["a\nb"];
        yield 'empty' => [''];
    }

    #[DataProvider('strings')]
    public function testStringLiteralEvaluatesToTheSameString(string $value): void
    {
        self::assertSame($value, eval('return ' . stringLiteral($value) . ';'));
    }

    #[DataProvider('strings')]
    public function testSingleLineStringLiteralEvaluatesToTheSameStringOnOneLine(string $value): void
    {
        $literal = singleLineStringLiteral($value);

        self::assertStringNotContainsString("\n", $literal);
        self::assertSame($value, eval('return ' . $literal . ';'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function texts(): iterable
    {
        yield 'single line keeps one space at each end' => ['  a   b  ', ' a b '];
        yield 'tabs' => ["a\t\tb", 'a b'];
        yield 'leading and trailing newlines dropped' => ["\n    a\n  ", 'a'];
        yield 'inner newline becomes a space' => ["a\n   b", 'a b'];
        yield 'spaces before a newline at the start' => ["  \n  a b", 'a b'];
        yield 'only whitespace with a newline' => ["  \n  ", ''];
        yield 'only spaces' => ['   ', ' '];
    }

    #[DataProvider('texts')]
    public function testNormalizeText(string $text, string $expected): void
    {
        self::assertSame($expected, normalizeText($text));
    }

    public function testDecodeEntities(): void
    {
        self::assertSame('<a> & "b" \'c\' © ' . "\n", decodeEntities('&lt;a&gt; &amp; &quot;b&quot; &apos;c&apos; &copy; &#10;'));
        self::assertSame('&unknown;', decodeEntities('&unknown;'));
    }
}
