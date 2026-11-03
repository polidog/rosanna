<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Runtime;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 *
 * コンパイル済みのファイルで起きた例外を `.psx` のパスで読めるようにする (CP-150〜152)。
 * 行番号は .psx と一致する (CP-101) ので変換しない。書き換えは例外を投げない。
 */

/**
 * 付随の `<成果物>.meta` の source を引く。対応が分からないパスはそのまま返す
 *
 * @internal
 */
function rewriteFile(string $file): string
{
    if (!str_ends_with($file, '.php')) {
        return $file;
    }

    $metaPath = $file . '.meta';
    $json = is_file($metaPath) ? @file_get_contents($metaPath) : false;
    if ($json === false) {
        return $file;
    }

    $meta = json_decode($json, true);
    $source = is_array($meta) ? ($meta['source'] ?? null) : null;

    return is_string($source) && $source !== '' ? $source : $file;
}

/**
 * @param array<array-key, array<string, mixed>> $frames
 * @return array<array-key, array<string, mixed>>
 *
 * @internal
 */
function rewriteFrames(array $frames): array
{
    foreach ($frames as $i => $frame) {
        if (is_string($frame['file'] ?? null)) {
            $frames[$i]['file'] = rewriteFile($frame['file']);
        }
    }

    return $frames;
}

/**
 * @internal
 */
function formatException(\Throwable $e): string
{
    $text = sprintf("%s: %s at %s:%d\n", $e::class, $e->getMessage(), rewriteFile($e->getFile()), $e->getLine());

    $number = 0;
    foreach (rewriteFrames($e->getTrace()) as $frame) {
        $file = is_string($frame['file'] ?? null) ? $frame['file'] : '[internal]';
        $line = is_int($frame['line'] ?? null) ? $frame['line'] : 0;
        $text .= sprintf("#%d %s(%d): %s()\n", $number++, $file, $line, frameCallName($frame));
    }
    $text .= "#{$number} {main}";

    $previous = $e->getPrevious();

    return $previous === null ? $text : $text . "\nCaused by: " . formatException($previous);
}

/**
 * フレームの呼び出し名。クロージャの名前に含まれる成果物のパスも書き換える
 *
 * @param array<string, mixed> $frame
 *
 * @internal
 */
function frameCallName(array $frame): string
{
    $part = static fn(string $key): string => is_string($frame[$key] ?? null) ? $frame[$key] : '';

    // PHP 8.4 からクロージャの名前は `{closure:ファイル:行}` で、成果物のパスを含む
    $function = (string) preg_replace_callback(
        '/\{closure:(.+):(\d+)\}/',
        static fn(array $m): string => '{closure:' . rewriteFile($m[1]) . ':' . $m[2] . '}',
        $part('function'),
    );

    return $part('class') . $part('type') . $function;
}
