<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\endsWithLineComment;
use function Polidog\Rosanna\Internal\Functions\Psx\findStop;
use function Polidog\Rosanna\Internal\Functions\Psx\hasNamespaceDeclaration;
use function Polidog\Rosanna\Internal\Functions\Psx\isExpressionStartChar;
use function Polidog\Rosanna\Internal\Functions\Psx\isExpressionStartToken;
use function Polidog\Rosanna\Internal\Functions\Psx\operatorToken;
use function Polidog\Rosanna\Internal\Functions\Psx\startsTag;

/** CP-15、CP-81、CP-82、CP-94 の走査に使うトークンの判定 */
final class TokensTest extends TestCase
{
    /** @return iterable<string, array{string, array{int, bool}|null}> */
    public static function tagStarts(): iterable
    {
        yield 'after return' => ['<?php return <div/>;', [13, false]];
        yield 'fragment' => ['<?php return <>x</>;', [13, false]];
        yield 'in call' => ['<?php f(<b/>);', [8, false]];
        yield 'in array' => ['<?php $x = [1, <i/>];', [15, false]];
        yield 'after &&' => ['<?php return $a && <a/>;', [19, false]];
        yield 'after ??' => ['<?php $a ?? <a/>;', [12, false]];
        yield 'after fn =>' => ['<?php return fn() => <a/>;', [21, false]];
        yield 'keyword as tag name' => ['<?php return <var/>;', [13, false]];
        yield 'after interpolation braces' => ['<?php $x = "{$a}"; return <a/>;', [26, false]];
        yield 'less than' => ['<?php $a < $b;', null];
        yield 'less than without spaces' => ['<?php $a<b>;', null];
        yield 'less than a number' => ['<?php return 1 < 2;', null];
        yield 'not equal' => ['<?php $a <> $b;', null];
        yield 'in comment' => ["<?php // <a>\n", null];
        yield 'in string' => ['<?php $s = "<a>";', null];
    }

    /** @param array{int, bool}|null $expected */
    #[DataProvider('tagStarts')]
    public function testFindStopFindsTagStarts(string $code, ?array $expected): void
    {
        self::assertSame($expected, findStop($code, false));
        self::assertSame($expected, findStop($code, true));
    }

    /** @return iterable<string, array{string, int}> */
    public static function closingBraces(): iterable
    {
        yield 'call' => ['<?php f() }', 10];
        yield 'array' => ['<?php [1, 2] }', 13];
        yield 'nested braces' => ['<?php { } }', 10];
        yield 'interpolation' => ['<?php "{$a}" }', 13];
        yield 'stops before a later tag' => ['<?php fn() => [1] } <a/>', 18];
    }

    #[DataProvider('closingBraces')]
    public function testFindStopStopsAtTheClosingBraceOnlyInAnExpression(string $code, int $offset): void
    {
        self::assertSame([$offset, true], findStop($code, true));
        self::assertNull(findStop($code, false));
    }

    public function testStartsTag(): void
    {
        self::assertTrue(startsTag([\T_STRING, 'div', 1]));
        self::assertTrue(startsTag([\T_STRING, '_x', 1]));
        self::assertTrue(startsTag([\T_SWITCH, 'switch', 1]));
        self::assertTrue(startsTag('/'));
        self::assertTrue(startsTag('>'));
        self::assertFalse(startsTag([\T_VARIABLE, '$a', 1]));
        self::assertFalse(startsTag([\T_LNUMBER, '1', 1]));
        self::assertFalse(startsTag([\T_WHITESPACE, ' ', 1]));
        self::assertFalse(startsTag('='));
        self::assertFalse(startsTag(null));
    }

    public function testExpressionStart(): void
    {
        foreach (str_split('=,([{;?:!&|^+-*/%.') as $char) {
            self::assertTrue(isExpressionStartChar($char), $char);
        }
        foreach ([')', ']', '}', '"', '$', '@', '~', '', '=='] as $char) {
            self::assertFalse(isExpressionStartChar($char), $char);
        }

        self::assertTrue(isExpressionStartToken(\T_RETURN));
        self::assertTrue(isExpressionStartToken(\T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG));
        self::assertFalse(isExpressionStartToken(\T_VARIABLE));
        self::assertFalse(isExpressionStartToken(\T_STRING));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function namespaceSources(): iterable
    {
        yield 'none' => ['<?php return 1;', false];
        yield 'named' => ['<?php namespace App;', true];
        yield 'named with comment' => ['<?php namespace /* x */ App;', true];
        yield 'global block' => ['<?php namespace { return 1; }', false];
        yield 'in comment' => ['<?php // namespace App;', false];
        yield 'in string' => ['<?php $s = "namespace App";', false];
        yield 'relative name is not a declaration' => ['<?php return namespace\f();', false];
    }

    #[DataProvider('namespaceSources')]
    public function testHasNamespaceDeclaration(string $source, bool $expected): void
    {
        self::assertSame($expected, hasNamespaceDeclaration(token_get_all($source)));
    }

    public function testEndsWithLineComment(): void
    {
        self::assertTrue(endsWithLineComment('$a // x'));
        self::assertTrue(endsWithLineComment('$a # x'));
        self::assertFalse(endsWithLineComment('$a /* x */'));
        self::assertFalse(endsWithLineComment("\$a // x\n+ 1"));
        self::assertFalse(endsWithLineComment('"// x"'));
    }

    public function testOperatorTokenSkipsParenthesesAndComments(): void
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $statements = $parser->parse('<?php ($a) /* && */ and ($b);');
        $statement = $statements[0] ?? null;
        self::assertInstanceOf(Expression::class, $statement);
        self::assertInstanceOf(BinaryOp\LogicalAnd::class, $statement->expr);

        $token = operatorToken($parser->getTokens(), $statement->expr);

        self::assertSame(\T_LOGICAL_AND, $token->id);
        self::assertSame('and', $token->text);
    }
}
