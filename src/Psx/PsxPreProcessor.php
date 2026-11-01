<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use Polidog\Rosanna\Psx\Ast\PsxCode;
use Polidog\Rosanna\Psx\Ast\PsxRegion;

/**
 * PHP のソースの中から PSX の領域を探し、プレースホルダの関数呼び出しに置き換える (§3)。
 *
 * PSX のテキストは PHP のトークナイザーを壊す (アポストロフィ、`#`、`//`) ので、最初の領域より前の
 * トークンしか信用できない。領域を 1 つ見つけるたびに置き換えて、先頭からトークン化し直す (CP-83)。
 * `{式}` の中も同じ走査で読むので、タグのテキストが括弧の対応を狂わせない。
 *
 * @internal
 */
final class PsxPreProcessor
{
    public const string PLACEHOLDER_NAMESPACE = '\\Polidog\\Rosanna\\Psx\\Internal\\';

    /** CP-82 */
    private const array EXPRESSION_START_TOKENS = [
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
        \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
    ];

    /** CP-82 */
    private const string EXPRESSION_START_CHARS = '=,([{;?:!&|^+-*/%.';

    public function process(string $source): PsxCode
    {
        $code = $this->scan($source, 0, '');
        \assert($code !== null);

        return $code;
    }

    /**
     * `{` の直後から閉じる `}` までを読む。閉じていなければ null。
     */
    public function processExpression(string $source, int $start): ?PsxCode
    {
        return $this->scan($source, $start, '<?php ');
    }

    public static function placeholder(int $index): string
    {
        return self::PLACEHOLDER_NAMESPACE . "__psx_region_{$index}__()";
    }

    /**
     * @param string $prefix 空ならファイル全体を読む。そうでなければ `{式}` を読むためのトークナイザーの開始タグ
     */
    private function scan(string $source, int $start, string $prefix): ?PsxCode
    {
        $isExpression = $prefix !== '';
        $done = $prefix;
        $cursor = $start;
        $regions = [];

        while (true) {
            $working = $done . \substr($source, $cursor);
            $stop = $this->findStop($working, $isExpression);

            if ($stop === null) {
                if ($isExpression) {
                    return null;
                }

                return new PsxCode($working, $regions, $start, \strlen($source));
            }

            [$offset, $isClosingBrace] = $stop;
            // 置き換え済みの部分より後ろはソースと同じなので、差分で元の位置へ戻せる
            $sourceOffset = $cursor + $offset - \strlen($done);

            if ($isClosingBrace) {
                return new PsxCode(
                    \substr($working, \strlen($prefix), $offset - \strlen($prefix)),
                    $regions,
                    $start,
                    $sourceOffset,
                );
            }

            $parser = new PsxParser($source, $sourceOffset, $this);
            $element = $parser->parseElement();
            $cursor = $parser->position();

            $done = \substr($working, 0, $offset) . self::placeholder(\count($regions));
            $regions[] = new PsxRegion($element, $sourceOffset, $cursor, $offset - \strlen($prefix));
        }
    }

    /**
     * @return array{0: int, 1: bool}|null 位置と、それが `{式}` を閉じる `}` かどうか
     */
    private function findStop(string $code, bool $isExpression): ?array
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
                if (\in_array($id, self::EXPRESSION_START_TOKENS, true)) {
                    $expectExpression = true;
                } elseif (!\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    $expectExpression = false;
                }
                continue;
            }

            if ($token === '<' && $expectExpression && $this->startsTag($tokens[$i + 1] ?? null)) {
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
            if (\str_contains(self::EXPRESSION_START_CHARS, $token)) {
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
     * @param array{0: int, 1: string, 2: int}|string|null $next
     */
    private function startsTag(array|string|null $next): bool
    {
        if (\is_array($next)) {
            return \preg_match('/^[A-Za-z_]/', $next[1]) === 1;
        }

        return $next === '/' || $next === '>';
    }
}
