<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx\Ast;

/**
 * @internal
 */
final readonly class PsxRegion
{
    /**
     * @param int $start `.psx` での先頭の位置
     * @param int $end `.psx` での終端の位置
     * @param int $codeOffset プレースホルダの PsxCode::$code での位置
     */
    public function __construct(
        public PsxElement $element,
        public int $start,
        public int $end,
        public int $codeOffset,
    ) {}
}
