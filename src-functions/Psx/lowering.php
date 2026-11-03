<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * 右辺にタグを含む `&&`、`||`、`and`、`or` を、オペランドを返す三項演算子に書き換える (CP-65〜CP-68)。
 *
 * PHP のままでは bool になり、`{$x && <b/>}` が何も描画しない。AST を印字するとコメントと行が失われるので、
 * ソースへの差し込みで書き換える。
 *
 * @param bool $isExpression `{式}` の中身かどうか。`...$x` も解析できるよう配列の要素として読む
 * @param list<int> $regionOffsets コードの中のプレースホルダの位置
 * @return array{string, list<int>} 書き換えたコードと、書き換えた後のプレースホルダの位置
 *
 * @internal
 */
function lowerTagOperands(string $code, bool $isExpression, array $regionOffsets): array
{
    $prefix = '<?php [';
    $prefixLength = $isExpression ? \strlen($prefix) : 0;
    $parseable = $isExpression ? $prefix . $code . "\n];" : $code;

    $parser = new ParserFactory()->createForNewestSupportedVersion();
    try {
        $ast = $parser->parse($parseable);
    } catch (Error) {
        // 不正なコードの報告は CP-105 の検証で行う
        return [$code, $regionOffsets];
    }
    if ($ast === null) {
        return [$code, $regionOffsets];
    }

    $finder = new NodeFinder();
    // 同じ名前の関数を利用者が呼んでいても領域ではないので、名前ではなく位置で見分ける
    $regionPositions = \array_flip($regionOffsets);
    $isPlaceholder = static fn(Node $node): bool => $node instanceof FuncCall
        && isset($regionPositions[$node->getStartFilePos() - $prefixLength]);

    $operators = $finder->find(
        $ast,
        static fn(Node $node): bool => ($node instanceof BooleanAnd || $node instanceof BooleanOr
                || $node instanceof LogicalAnd || $node instanceof LogicalOr)
            && $finder->findFirst($node->right, $isPlaceholder) !== null,
    );

    $tokens = $parser->getTokens();
    $edits = [];
    // 内側と外側の演算子の終端が同じ位置になることがあるので、内側を先に積む
    foreach (\array_reverse($operators) as $operator) {
        \assert($operator instanceof BooleanAnd || $operator instanceof BooleanOr
            || $operator instanceof LogicalAnd || $operator instanceof LogicalOr);
        $isAnd = $operator instanceof BooleanAnd || $operator instanceof LogicalAnd;
        $token = operatorToken($tokens, $operator);

        $edits[] = [$operator->getStartFilePos() - $prefixLength, 0, '(('];
        // 演算子の前後の空白は捨てる。改行は行番号のために残す
        $tokenStart = $token->pos - $prefixLength;
        $tokenEnd = $tokenStart + \strlen($token->text);
        $editStart = \strlen(\rtrim(\substr($code, 0, $tokenStart), " \t"));
        $editEnd = $tokenEnd + \strspn($code, " \t", $tokenEnd);
        $edits[] = [$editStart, $editEnd - $editStart, $isAnd ? ') ? (' : ') ?: ('];
        $edits[] = [$operator->getEndFilePos() + 1 - $prefixLength, 0, $isAnd ? ') : false)' : '))'];
    }

    return [applyEdits($code, $edits), shiftOffsets($regionOffsets, $edits)];
}
