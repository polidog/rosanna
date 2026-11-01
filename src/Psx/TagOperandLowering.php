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
            $token = $this->operatorToken($tokens, $operator);

            $edits[] = [$operator->getStartFilePos() - $prefixLength, 0, '(('];
            // 演算子の前後の空白は捨てる。改行は行番号のために残す
            $tokenStart = $token->pos - $prefixLength;
            $tokenEnd = $tokenStart + \strlen($token->text);
            $editStart = \strlen(\rtrim(\substr($code, 0, $tokenStart), " \t"));
            $editEnd = $tokenEnd + \strspn($code, " \t", $tokenEnd);
            $edits[] = [$editStart, $editEnd - $editStart, $isAnd ? ') ? (' : ') ?: ('];
            $edits[] = [$operator->getEndFilePos() + 1 - $prefixLength, 0, $isAnd ? ') : false)' : '))'];
        }

        return [$this->applyEdits($code, $edits), $this->shiftOffsets($regionOffsets, $edits)];
    }

    /**
     * 領域の先頭と同じ位置の編集は領域の前に差し込まれる。領域の終端の編集は先頭より後ろなので動かさない。
     *
     * @param list<int> $offsets
     * @param list<array{int, int, string}> $edits
     * @return list<int>
     */
    private function shiftOffsets(array $offsets, array $edits): array
    {
        return \array_map(static function (int $offset) use ($edits): int {
            $shifted = $offset;
            foreach ($edits as [$position, $length, $text]) {
                if ($position <= $offset) {
                    $shifted += \strlen($text) - $length;
                }
            }

            return $shifted;
        }, $offsets);
    }

    /**
     * 左右のオペランドの間には括弧とコメントと演算子しかない。
     *
     * @param array<\PhpParser\Token> $tokens
     */
    private function operatorToken(array $tokens, BooleanAnd|BooleanOr|LogicalAnd|LogicalOr $operator): \PhpParser\Token
    {
        for ($i = $operator->left->getEndTokenPos() + 1; $i < $operator->right->getStartTokenPos(); $i++) {
            if (\in_array($tokens[$i]->id, [\T_BOOLEAN_AND, \T_BOOLEAN_OR, \T_LOGICAL_AND, \T_LOGICAL_OR], true)) {
                return $tokens[$i];
            }
        }

        throw new \LogicException('Logical operator token not found between its operands.');
    }

    /**
     * @param list<array{int, int, string}> $edits 位置、置き換える長さ、差し込む文字列
     */
    private function applyEdits(string $code, array $edits): string
    {
        // usort は安定なので、同じ位置の編集は積んだ順のまま
        \usort($edits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $output = '';
        $cursor = 0;
        foreach ($edits as [$offset, $length, $text]) {
            $output .= \substr($code, $cursor, $offset - $cursor) . $text;
            $cursor = $offset + $length;
        }

        return $output . \substr($code, $cursor);
    }
}
