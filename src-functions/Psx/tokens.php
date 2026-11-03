<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 *
 * PHP のトークン列を読む。php-parser の名前は型の宣言にしか書かないので、このファイルを読み込んでも
 * php-parser のクラスは読み込まれない (CP-2)。
 */

use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Token;

/**
 * PSX の領域の始まりか、`{式}` を閉じる `}` を探す (CP-81、CP-82)。
 *
 * @internal
 * @param bool $isExpression `{式}` の中を読んでいるか。true なら深さ 0 の `}` で止まる
 * @return array{0: int, 1: bool}|null 位置と、それが `{式}` を閉じる `}` かどうか。どちらもなければ null
 */
function findStop(string $code, bool $isExpression): ?array
{
    $tokens = \token_get_all($code);
    $expectExpression = true;
    $offset = 0;
    $depth = 0;

    foreach ($tokens as $i => $token) {
        if (\is_array($token)) {
            [$id, $text] = $token;

            if ($id === \T_IS_NOT_EQUAL && $text === '<>' && $expectExpression) {
                return [$offset, false];
            }
            if ($id === \T_CURLY_OPEN || $id === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            }

            $offset += \strlen($text);
            if (isExpressionStartToken($id)) {
                $expectExpression = true;
            } elseif (!\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $expectExpression = false;
            }
            continue;
        }

        if ($token === '<' && $expectExpression && startsTag($tokens[$i + 1] ?? null)) {
            return [$offset, false];
        }
        if ($token === '{') {
            $depth++;
        } elseif ($token === '}') {
            if ($isExpression && $depth === 0) {
                return [$offset, true];
            }
            $depth--;
        }

        $offset++;
        if (isExpressionStartChar($token)) {
            $expectExpression = true;
        } elseif ($token === ')' || $token === ']' || $token === '}') {
            $expectExpression = false;
        }
    }

    return null;
}

/**
 * CP-81、CP-15: `var`、`switch`、`use` のようなキーワードも、式の開始位置ならタグ名である。
 *
 * @internal 引数は `<` の次のトークン
 * @param array{0: int, 1: string, 2: int}|string|null $next
 */
function startsTag(array|string|null $next): bool
{
    if (\is_array($next)) {
        return \preg_match('/^[A-Za-z_]/', $next[1]) === 1;
    }

    return $next === '/' || $next === '>';
}

/**
 * CP-82: この後に式が始まるトークン
 *
 * @internal
 */
function isExpressionStartToken(int $id): bool
{
    return match ($id) {
        \T_RETURN,
        \T_ECHO,
        \T_PRINT,
        \T_DOUBLE_ARROW,
        \T_OBJECT_OPERATOR,
        \T_NULLSAFE_OBJECT_OPERATOR,
        \T_OPEN_TAG,
        \T_OPEN_TAG_WITH_ECHO,
        \T_FN,
        \T_BOOLEAN_AND,
        \T_BOOLEAN_OR,
        \T_LOGICAL_AND,
        \T_LOGICAL_OR,
        \T_LOGICAL_XOR,
        \T_COALESCE,
        \T_COALESCE_EQUAL,
        \T_YIELD,
        \T_YIELD_FROM,
        // PHP 8.1 から `&` は文字ではなくこの 2 つのトークンになる
        \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
        \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG => true,
        default => false,
    };
}

/**
 * CP-82: この後に式が始まる 1 文字のトークン
 *
 * @internal
 */
function isExpressionStartChar(string $char): bool
{
    return match ($char) {
        '=', ',', '(', '[', '{', ';', '?', ':', '!', '&', '|', '^', '+', '-', '*', '/', '%', '.' => true,
        default => false,
    };
}

/**
 * コメントや文字列の中の「namespace foo」を宣言と見間違えないよう、トークンで判定する。
 * `namespace { ... }` は名前のないグローバルの宣言なので、宣言に数えない。
 *
 * @internal
 * @param array<array{0: int, 1: string, 2: int}|string> $tokens
 */
function hasNamespaceDeclaration(array $tokens): bool
{
    $afterKeyword = false;
    foreach ($tokens as $token) {
        if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            continue;
        }
        if ($afterKeyword && $token !== '{') {
            return true;
        }
        $afterKeyword = \is_array($token) && $token[0] === \T_NAMESPACE;
    }

    return false;
}

/**
 * `//` か `#` のコメントで終わる式か。後ろに書くものがコメントに飲まれる
 *
 * @internal
 */
function endsWithLineComment(string $code): bool
{
    $tokens = \token_get_all('<?php ' . $code);
    $last = \end($tokens);

    return \is_array($last) && $last[0] === \T_COMMENT && !\str_starts_with($last[1], '/*');
}

/**
 * 論理演算子のトークン。左右のオペランドの間には括弧とコメントと演算子しかない。
 *
 * @internal
 * @param array<Token> $tokens
 * @throws \LogicException 演算子が見つからないとき
 */
function operatorToken(array $tokens, BooleanAnd|BooleanOr|LogicalAnd|LogicalOr $operator): Token
{
    for ($i = $operator->left->getEndTokenPos() + 1; $i < $operator->right->getStartTokenPos(); $i++) {
        if (\in_array($tokens[$i]->id, [\T_BOOLEAN_AND, \T_BOOLEAN_OR, \T_LOGICAL_AND, \T_LOGICAL_OR], true)) {
            return $tokens[$i];
        }
    }

    throw new \LogicException('Logical operator token not found between its operands.');
}
