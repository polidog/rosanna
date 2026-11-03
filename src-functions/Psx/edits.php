<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 *
 * 編集は `array{int, int, string}` (位置、置き換える長さ、差し込む文字列) で表す。位置は元のコードでのもの。
 */

/**
 * 編集をまとめて当てる。同じ位置の編集は積んだ順に差し込む
 *
 * @param list<array{int, int, string}> $edits 位置、置き換える長さ、差し込む文字列
 *
 * @internal
 */
function applyEdits(string $code, array $edits): string
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

/**
 * 元のコードでの位置を applyEdits() の後の位置に直す。位置がオフセットより前か同じ編集の分だけずらす。
 * 領域の先頭と同じ位置の編集は領域の前に差し込まれる。領域の終端の編集は先頭より後ろなので動かさない。
 *
 * @param list<int> $offsets
 * @param list<array{int, int, string}> $edits
 * @return list<int>
 *
 * @internal
 */
function shiftOffsets(array $offsets, array $edits): array
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
