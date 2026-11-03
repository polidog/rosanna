<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\applyEdits;
use function Polidog\Rosanna\Internal\Functions\Psx\shiftOffsets;

/** CP-65〜CP-68 の書き換えに使う編集 */
final class EditsTest extends TestCase
{
    /** @return iterable<string, array{string, list<array{int, int, string}>, string}> */
    public static function edits(): iterable
    {
        yield 'no edits' => ['abcdef', [], 'abcdef'];
        yield 'empty edit' => ['abcdef', [[2, 0, '']], 'abcdef'];
        yield 'insert' => ['abcdef', [[2, 0, 'X']], 'abXcdef'];
        yield 'insert at the start' => ['abcdef', [[0, 0, 'X']], 'Xabcdef'];
        yield 'insert at the end' => ['abcdef', [[6, 0, 'X']], 'abcdefX'];
        yield 'replace' => ['abcdef', [[2, 2, 'XYZ']], 'abXYZef'];
        yield 'delete' => ['abcdef', [[2, 2, '']], 'abef'];
        yield 'out of order' => ['abcdef', [[4, 0, 'Y'], [1, 0, 'X']], 'aXbcdYef'];
        // usort が安定なので、同じ位置の編集は積んだ順に並ぶ
        yield 'same position in pushed order' => ['abcdef', [[2, 0, '1'], [2, 0, '2'], [2, 0, '3']], 'ab123cdef'];
        yield 'same position mixed with others' => ['abcdef', [[4, 0, 'Y'], [2, 0, '1'], [0, 0, 'X'], [2, 0, '2']], 'Xab12cdYef'];
        yield 'insert then replace at the same position' => ['abcdef', [[2, 0, '(('], [2, 2, '?']], 'ab((?ef'];
        yield 'operator lowering' => ['$a && $b', [[0, 0, '(('], [2, 4, ') ? ('], [8, 0, ') : false)']], '(($a) ? ($b) : false)'];
    }

    /** @param list<array{int, int, string}> $edits */
    #[DataProvider('edits')]
    public function testApplyEdits(string $code, array $edits, string $expected): void
    {
        self::assertSame($expected, applyEdits($code, $edits));
    }

    /** @return iterable<string, array{list<array{int, int, string}>, list<int>}> */
    public static function shifts(): iterable
    {
        // オフセットは 5
        yield 'no edits' => [[], [5]];
        yield 'empty edit before' => [[[2, 0, '']], [5]];
        yield 'insert before' => [[[2, 0, 'XYZ']], [8]];
        yield 'insert at the same position' => [[[5, 0, 'XYZ']], [8]];
        yield 'insert after' => [[[6, 0, 'XYZ']], [5]];
        yield 'replace with longer before' => [[[1, 2, 'XYZW']], [7]];
        yield 'replace with shorter before' => [[[1, 3, 'X']], [3]];
        yield 'delete before' => [[[1, 3, '']], [2]];
        yield 'replace at the same position' => [[[5, 2, 'X']], [4]];
        yield 'several before and after' => [[[0, 0, '(('], [3, 1, 'ab'], [5, 0, '!'], [9, 0, '))']], [9]];
    }

    /**
     * @param list<array{int, int, string}> $edits
     * @param list<int> $expected
     */
    #[DataProvider('shifts')]
    public function testShiftOffsets(array $edits, array $expected): void
    {
        self::assertSame($expected, shiftOffsets([5], $edits));
    }

    public function testShiftOffsetsKeepsTheOrderOfOffsets(): void
    {
        self::assertSame([], shiftOffsets([], [[0, 0, 'X']]));
        self::assertSame([7, 0, 3], shiftOffsets([6, 0, 2], [[1, 0, 'X']]));
    }

    public function testShiftedOffsetPointsAtTheSameText(): void
    {
        $code = '$a && f()';
        $edits = [[0, 0, '(('], [2, 4, ') ? ('], [9, 0, ') : false)']];

        [$offset] = shiftOffsets([6], $edits);

        self::assertSame('f()', \substr(applyEdits($code, $edits), $offset, 3));
    }
}
