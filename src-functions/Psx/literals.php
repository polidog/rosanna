<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/**
 * CP-75: PHP の単一引用符の文字列リテラル
 *
 * @internal
 */
function stringLiteral(string $value): string
{
    return "'" . \addcslashes($value, "'\\") . "'";
}

/**
 * CP-102: `&#10;` をデコードした改行をそのまま書くと、ソースより行が増える。改行は `"\n"` の連結にする
 *
 * @internal
 */
function singleLineStringLiteral(string $value): string
{
    return \implode(' . "\n" . ', \array_map(stringLiteral(...), \explode("\n", $value)));
}

/**
 * CP-40、CP-41: PSX のテキストの空白をまとめる
 *
 * @internal
 */
function normalizeText(string $text): string
{
    if (\str_contains($text, "\n")) {
        $text = \preg_replace('/\A[ \t\r\f\v]*\n\s*|\s*\n[ \t\r\f\v]*\z/', '', $text) ?? $text;
    }

    return \preg_replace('/\s+/', ' ', $text) ?? $text;
}

/**
 * CP-50
 *
 * @internal
 */
function decodeEntities(string $text): string
{
    return \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
}
