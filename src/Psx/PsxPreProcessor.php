<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use function Polidog\Rosanna\Internal\Functions\Psx\findStop;

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
            $stop = findStop($working, $isExpression);

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
}
