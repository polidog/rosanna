<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\lowerTagOperands;

/** CP-65〜CP-68: 右辺にタグを含む論理演算子の書き換え */
final class LoweringTest extends TestCase
{
    /** @return iterable<string, array{string, bool, list<int>, string}> */
    public static function lowerings(): iterable
    {
        yield 'and in an expression' => ['$a && p(0)', true, [6], '(($a) ? (p(0)) : false)'];
        yield 'or in an expression' => ['$a || p(0)', true, [6], '(($a) ?: (p(0)))'];
        yield 'word and' => ['$a and p(0)', true, [7], '(($a) ? (p(0)) : false)'];
        yield 'statement' => ['<?php return $a && p(0);', false, [19], '<?php return (($a) ? (p(0)) : false);'];
        yield 'newline is kept' => ["\$a\n&& p(0)", true, [6], "((\$a\n) ? (p(0)) : false)"];
        yield 'tag on the left only' => ['p(0) && $a', true, [0], 'p(0) && $a'];
        yield 'same name but not a region' => ['$a && p(0)', true, [], '$a && p(0)'];
        yield 'invalid code' => ['$a && (', true, [], '$a && ('];
    }

    /**
     * 書き換えた後の位置も、プレースホルダ `p(` を指す
     *
     * @param list<int> $offsets
     */
    #[DataProvider('lowerings')]
    public function testLowerTagOperands(string $code, bool $isExpression, array $offsets, string $expected): void
    {
        [$lowered, $loweredOffsets] = lowerTagOperands($code, $isExpression, $offsets);

        self::assertSame($expected, $lowered);
        self::assertCount(\count($offsets), $loweredOffsets);
        foreach ($loweredOffsets as $offset) {
            self::assertSame('p(', \substr($lowered, $offset, 2));
        }
    }
}
