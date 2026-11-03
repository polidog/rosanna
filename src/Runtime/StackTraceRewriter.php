<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use function Polidog\Rosanna\Internal\Functions\Runtime\formatException;
use function Polidog\Rosanna\Internal\Functions\Runtime\rewriteFile;
use function Polidog\Rosanna\Internal\Functions\Runtime\rewriteFrames;

/**
 * コンパイル済みのファイルで起きた例外を `.psx` のパスで読めるようにする (CP-150〜152)。
 * 行番号は .psx と一致する (CP-101) ので変換しない。書き換えは例外を投げない。
 *
 * 中身は `src-functions/Runtime/traces.php` の関数にある。
 */
final class StackTraceRewriter
{
    /** 付随の `<成果物>.meta` の source を引く。対応が分からないパスはそのまま返す */
    public static function rewriteFile(string $file): string
    {
        return rewriteFile($file);
    }

    /**
     * @param array<array-key, array<string, mixed>> $frames
     * @return array<array-key, array<string, mixed>>
     */
    public static function rewriteFrames(array $frames): array
    {
        return rewriteFrames($frames);
    }

    public static function formatException(\Throwable $e): string
    {
        return formatException($e);
    }
}
