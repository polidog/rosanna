<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Runtime;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

use Polidog\Rosanna\Runtime\Element;

/**
 * ENT_SUBSTITUTE がないと、不正な UTF-8 の 1 バイトで値全体が空になる (RT-3.1)
 *
 * @internal
 */
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @internal エラーメッセージに値を埋めるときに使う */
function quote(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * HTML の option の value と同じく、ASCII の空白を詰めて前後を除く
 *
 * @internal
 */
function collapseWhitespace(string $text): string
{
    return trim((string) preg_replace('/[\t\n\f\r ]+/', ' ', $text), ' ');
}

/**
 * 子孫のテキストを連結する。配列は平らにして読む。Renderable は見ないので、解決してから渡す
 *
 * @internal
 * @param array<mixed> $children
 */
function textOf(array $children): string
{
    $text = '';
    foreach ($children as $child) {
        if ($child instanceof Element) {
            $text .= textOf($child->children);
        } elseif (is_array($child)) {
            $text .= textOf($child);
        } elseif (is_string($child) || is_int($child) || is_float($child) || $child instanceof \Stringable) {
            $text .= (string) $child;
        }
    }

    return $text;
}

/**
 * script と style の子を連結する。パーサーがデコードしないのでエスケープしない (RT-3.12)
 *
 * @internal
 * @param array<mixed> $children
 * @throws \RuntimeException テキストでない子があるとき
 */
function joinRawText(array $children): string
{
    $text = '';
    foreach ($children as $child) {
        if (is_array($child)) {
            $text .= joinRawText($child);
        } elseif (is_string($child) || is_int($child) || is_float($child) || $child instanceof \Stringable) {
            $text .= (string) $child;
        } elseif ($child !== null && !is_bool($child)) {
            throw new \RuntimeException(sprintf('Cannot render a child of type %s inside <script> or <style>: only text is allowed.', get_debug_type($child)));
        }
    }

    return $text;
}
