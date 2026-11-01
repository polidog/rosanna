<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx\Ast;

/**
 * @internal
 */
final readonly class PsxText
{
    /**
     * @param string $value 出力する文字列
     * @param int $start `.psx` での最初の文字の位置
     * @param bool $raw `style`、`script` の中身。改行もソースのまま出力する
     */
    public function __construct(
        public string $value,
        public int $start,
        public bool $raw = false,
    ) {}
}
