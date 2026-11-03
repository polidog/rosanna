<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\assertSingleExpression;
use function Polidog\Rosanna\Internal\Functions\Psx\assertValidPhp;
use function Polidog\Rosanna\Internal\Functions\Psx\parseStatements;

use Polidog\Rosanna\Psx\Ast\PsxCode;

/** CP-21、CP-30、CP-73、CP-105、CP-107 */
final class SyntaxTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function singleExpressions(): iterable
    {
        yield 'variable' => ['$a'];
        yield 'call' => ['f($a, $b)'];
        yield 'ternary' => ['$a ? 1 : 2'];
        yield 'closure' => ['fn() => [1, 2]'];
        yield 'spread' => ['...$props'];
        yield 'trailing line comment' => ['$a // note'];
        yield 'blank' => ['  '];
        yield 'comment only' => ['/* x */'];
    }

    #[DataProvider('singleExpressions')]
    public function testSingleExpressionPasses(string $code): void
    {
        $this->expectNotToPerformAssertions();

        assertSingleExpression(new PsxCode($code, [], 0, \strlen($code)), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function notSingleExpressions(): iterable
    {
        yield 'two expressions' => ['$a, $b'];
        yield 'statement' => ['$a; $b'];
        yield 'closes the array' => ['1]); f([2'];
        yield 'closes the parentheses' => ['1)); f((2'];
        yield 'spread twice' => ['...$a, ...$b'];
        yield 'invalid' => ['$a +'];
    }

    #[DataProvider('notSingleExpressions')]
    public function testNotSingleExpressionIsAnErrorAtItsLine(string $code): void
    {
        $source = "<?php\nreturn <a x={" . $code . "}/>;\n";
        $start = (int) strpos($source, '{') + 1;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("PSX compile error at line 2: {...} must hold a single expression\n    return <a x={");

        assertSingleExpression(new PsxCode($code, [], $start, $start + \strlen($code)), $source);
    }

    public function testParseStatements(): void
    {
        self::assertCount(2, parseStatements('<?php $a; $b;') ?? []);
        self::assertSame([], parseStatements('<?php '));
        self::assertNull(parseStatements('<?php $a +'));
    }

    public function testAssertValidPhpShowsTheSourceLine(): void
    {
        assertValidPhp("<?php\nreturn 1;\n", 'unused');

        try {
            assertValidPhp("<?php\nreturn 1;\nreturn +;\n", "<?php\nreturn 1;\nreturn <a/> +;\n");
            self::fail('Expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('PSX compile error at line 3: generated PHP is invalid: ', $e->getMessage());
            self::assertStringEndsWith("\n    return <a/> +;", $e->getMessage());
            self::assertInstanceOf(\PhpParser\Error::class, $e->getPrevious());
        }
    }
}
