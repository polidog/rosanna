<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Runtime;

/**
 * 補間の前に検証する。要素を黙って落とさない (RT-3.4)。`/D` がないと `$` が末尾の改行の前にも合う
 *
 * @internal
 * @throws \RuntimeException
 */
function assertValidTagName(string $tag): void
{
    $pattern = '/^[A-Za-z][A-Za-z0-9\-]*$/D';
    if (preg_match($pattern, $tag) !== 1) {
        throw new \RuntimeException(sprintf('Invalid HTML tag name %s: tag names must match %s.', quote($tag), $pattern));
    }
}

/** @internal 引数は小文字にしたタグ名 */
function isVoidTag(string $lowerTag): bool
{
    return match ($lowerTag) {
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' => true,
        default => false,
    };
}

/** @internal 引数は小文字にしたタグ名 */
function isRawTextTag(string $lowerTag): bool
{
    return $lowerTag === 'script' || $lowerTag === 'style';
}

/**
 * 中身をテキストとして読み、自身の終了タグでしか閉じない要素。エスケープしない raw text がこの中にあると閉じられる (RT-3.12)
 *
 * @internal 引数は小文字にしたタグ名
 */
function isTextOnlyAncestor(string $lowerTag): bool
{
    return match ($lowerTag) {
        'noscript', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes' => true,
        default => false,
    };
}

/** @internal 引数は小文字にしたタグ名。RT-3.14 この中ではパーサーが script と style の中身をマークアップとして読む */
function isForeignContentRoot(string $lowerTag): bool
{
    return $lowerTag === 'svg' || $lowerTag === 'math';
}

/** @internal 引数は小文字にしたタグ名 */
function isAnimationTag(string $lowerTag): bool
{
    return $lowerTag === 'animate' || $lowerTag === 'set';
}
