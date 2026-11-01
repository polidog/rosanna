<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * DI を持たずに host が PSX コンポーネントの引数を渡すための口 (RT-11.3)。
 */
interface PsxArgumentResolver
{
    /**
     * @param array<mixed> $metadata manifest の `parameters` (kind、name、service、value の項目のリスト)
     * @param array<string, mixed> $props
     * @return list<mixed> 第 2 引数以降
     */
    public function resolve(string $fqcn, array $metadata, array $props): array;
}
