<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Runtime;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/**
 * PHP ファイルを読み、返した値を返す。関数のローカル変数がファイルから見えないよう、引数だけを持つクロージャで読む。
 * 関数の中のクロージャはクラスのスコープを持たないので、ファイルのクロージャも `$this` やクラスのスコープを持たない
 *
 * @internal
 */
function requireIsolated(string $path): mixed
{
    $require = static fn(string $__path): mixed => require $__path;

    return $require($path);
}

/**
 * manifest のような、配列を返す PHP ファイルを読む
 *
 * @return array<mixed>
 * @throws \RuntimeException ファイルがない、読めない、配列を返さないとき
 *
 * @internal
 */
function readArrayFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new \RuntimeException("PSX manifest not found: {$path}. Run `vendor/bin/rosanna compile` to generate it.");
    }

    try {
        $data = requireIsolated($path);
    } catch (\ParseError $e) {
        throw new \RuntimeException("PSX manifest is invalid PHP: {$path}. ({$e->getMessage()})", previous: $e);
    }

    if (!is_array($data)) {
        throw new \RuntimeException("PSX manifest did not return an array: {$path}. Run `vendor/bin/rosanna compile` to regenerate.");
    }

    return $data;
}
