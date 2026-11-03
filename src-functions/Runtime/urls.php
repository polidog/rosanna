<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Runtime;

/**
 * RT-3.11。ブラウザの URL パーサーと同じ前処理をするので `\tjavascript:` や `java\tscript:` も通さない
 *
 * @internal
 */
function hasDangerousScheme(string $url): bool
{
    $normalized = ltrim(str_replace(["\t", "\n", "\r"], '', $url), "\x00..\x20");
    $colon = strpos($normalized, ':');
    if ($colon === false) {
        return false;
    }

    return match (strtolower(substr($normalized, 0, $colon))) {
        'javascript', 'vbscript', 'data' => true,
        default => false,
    };
}

/**
 * values は `;` 区切りで、後ろの値も時間が来れば適用される
 *
 * @internal
 */
function hasDangerousAnimationValue(string $value): bool
{
    foreach (explode(';', $value) as $part) {
        if (hasDangerousScheme($part)) {
            return true;
        }
    }

    return false;
}
