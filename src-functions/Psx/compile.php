<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/**
 * ソースの成果物のパス。`CompileCommand::cachePathFor()` の中身
 *
 * @internal
 */
function cachePathFor(string $cacheDir, string $sourcePath): string
{
    $absolute = \realpath($sourcePath);

    return \rtrim($cacheDir, \DIRECTORY_SEPARATOR)
        . \DIRECTORY_SEPARATOR
        . \sha1($absolute === false ? $sourcePath : $absolute)
        . '.php';
}

/**
 * @param array<string, string> $components FQCN => ソースのパス
 * @param array<string, string> $compiled ソースのパス => 成果物
 *
 * @return list<string> manifest の項目の行
 *
 * @internal
 */
function manifestEntries(array $components, array $compiled, string $cacheDir): array
{
    \ksort($components, \SORT_STRING);

    $lines = [];
    foreach ($components as $fqcn => $file) {
        $artifactPath = cachePathFor($cacheDir, $file);
        // `--check` では成果物を書かないので、今回コンパイルしたものはメモリから読む
        $artifact = $compiled[$file] ?? @\file_get_contents($artifactPath);
        $entries = \is_string($artifact) ? componentParameters($artifact, $fqcn) : [];

        $lines[] = '    ' . \var_export($fqcn, true) . ' => [';
        $lines[] = "        'file' => " . \var_export($artifactPath, true) . ',';
        if ($entries === []) {
            $lines[] = "        'parameters' => [],";
        } else {
            $lines[] = "        'parameters' => [";
            foreach ($entries as $entry) {
                $pairs = [];
                foreach ($entry as $key => $value) {
                    $pairs[] = "'$key' => " . \var_export($value, true);
                }
                $lines[] = '            [' . \implode(', ', $pairs) . '],';
            }
            $lines[] = '        ],';
        }
        $lines[] = '    ],';
    }

    return $lines;
}

/**
 * CP-144: 成果物の内容から決まる ID。同じ入力なら同じ値になるので `writeIfChanged()` の判定を壊さない。
 *
 * @param array<string, string> $sources ソースのパス => ソース
 * @param array<string, string> $compiled ソースのパス => 成果物
 * @param list<string> $entries manifest の項目の行
 *
 * @internal
 */
function buildId(array $sources, array $compiled, string $cacheDir, array $entries): string
{
    $context = \hash_init('sha256');
    \hash_update($context, \implode("\n", $entries));
    foreach (\array_keys($sources) as $file) {
        $artifact = $compiled[$file] ?? @\file_get_contents(cachePathFor($cacheDir, $file));
        \hash_update($context, "\0" . $file . "\0" . (\is_string($artifact) ? $artifact : ''));
    }

    return \hash_final($context);
}

/**
 * CP-136: closure を渡さない compile は defer を調べないので、残っている deferred manifest は古い。
 *
 * @return bool 消した (`--check` なら古い) かどうか
 *
 * @internal
 */
function removeStaleDeferredManifest(string $path, bool $check): bool
{
    if (!\file_exists($path)) {
        return false;
    }
    if (!$check && !@\unlink($path)) {
        throw new \RuntimeException("Error: failed to remove $path");
    }

    return true;
}

/**
 * @param list<string> $paths
 *
 * @return list<string> 重複を除いてソートした絶対パス
 *
 * @internal
 */
function collectSourceFiles(array $paths): array
{
    $files = [];
    foreach ($paths as $path) {
        if (\is_file($path)) {
            if (\str_ends_with($path, '.psx')) {
                $files[] = $path;
            }
            continue;
        }
        if (!\is_dir($path)) {
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if (!$entry->isLink() && $entry->isFile() && \str_ends_with($entry->getPathname(), '.psx')) {
                $files[] = $entry->getPathname();
            }
        }
    }

    $absolute = \array_map(static fn(string $file): string => \realpath($file) ?: $file, $files);
    $unique = \array_values(\array_unique($absolute));
    \sort($unique);

    return $unique;
}

/**
 * CP-125: `--watch` が変更を見つけるための、ソースの更新時刻
 *
 * @param list<string> $paths
 *
 * @return array<string, int> ソースのパス => 更新時刻
 *
 * @internal
 */
function modificationTimes(array $paths): array
{
    $times = [];
    foreach (collectSourceFiles($paths) as $file) {
        $mtime = \filemtime($file);
        if ($mtime !== false) {
            $times[$file] = $mtime;
        }
    }

    return $times;
}

/**
 * CP-121: 成果物の `.meta`。読めない、形が違うときは null
 *
 * @return array{hash: string, refs: list<string>}|null
 *
 * @internal
 */
function readMeta(string $path): ?array
{
    $content = \is_file($path) ? @\file_get_contents($path) : false;
    if ($content === false) {
        return null;
    }
    $data = \json_decode($content, true);
    if (!\is_array($data) || !\is_string($data['hash'] ?? null) || !\is_string($data['source'] ?? null) || !\is_array($data['refs'] ?? null)) {
        return null;
    }
    $refs = [];
    foreach ($data['refs'] as $ref) {
        if (!\is_string($ref)) {
            return null;
        }
        $refs[] = $ref;
    }

    return ['hash' => $data['hash'], 'refs' => $refs];
}

/**
 * @internal
 */
function ensureDirectory(string $directory): void
{
    if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
        throw new \RuntimeException("Error: cannot create cache directory $directory");
    }
}

/**
 * `$cwd` を基準に絶対パスにする。まだない出力先は、存在する親までを正規化する。
 *
 * @internal
 */
function absolutePath(string $path, string $cwd): string
{
    if (!\str_starts_with($path, '/') && \preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1) {
        $path = \rtrim($cwd, '/\\') . \DIRECTORY_SEPARATOR . $path;
    }
    $real = \realpath($path);
    if ($real !== false) {
        return $real;
    }
    $parent = \dirname($path);

    return $parent === $path ? $path : absolutePath($parent, $cwd) . \DIRECTORY_SEPARATOR . \basename($path);
}
