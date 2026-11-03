<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

use function Polidog\Rosanna\Internal\Functions\Psx\applyEdits;
use function Polidog\Rosanna\Internal\Functions\Psx\operatorToken;
use function Polidog\Rosanna\Internal\Functions\Psx\shiftOffsets;

/**
 * 右辺にタグを含む `&&`、`||`、`and`、`or` を、オペランドを返す三項演算子に書き換える (CP-65〜CP-68)。
 *
 * PHP のままでは bool になり、`{$x && <b/>}` が何も描画しない。AST を印字するとコメントと行が失われるので、
 * ソースへの差し込みで書き換える。
 *
 * @internal
 */
final class TagOperandLowering
{
    private const string EXPRESSION_PREFIX = '<?php [';

    private const string EXPRESSION_SUFFIX = "\n];";

    /**
     * @param bool $isExpression `{式}` の中身かどうか。`...$x` も解析できるよう配列の要素として読む
     * @param list<int> $regionOffsets コードの中のプレースホルダの位置
     * @return array{string, list<int>} 書き換えたコードと、書き換えた後のプレースホルダの位置
     */
    public function lower(string $code, bool $isExpression, array $regionOffsets): array
    {
        $prefixLength = $isExpression ? \strlen(self::EXPRESSION_PREFIX) : 0;
        $parseable = $isExpression ? self::EXPRESSION_PREFIX . $code . self::EXPRESSION_SUFFIX : $code;

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
}
