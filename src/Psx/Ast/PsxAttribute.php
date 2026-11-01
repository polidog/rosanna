<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx\Ast;

/**
 * @internal
 */
final readonly class PsxAttribute
{
    /**
     * @param string|PsxCode|null $value 文字列はデコード済みの値、PsxCode は `{式}`、null は値の省略 (true)
     */
    public function __construct(
        public string $name,
        public int $offset,
        public string|PsxCode|null $value,
        public int $valueOffset,
    ) {}
}
