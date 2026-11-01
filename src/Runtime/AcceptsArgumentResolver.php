<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * 自分で引数を解決するコンポーネントの目印 (RT-11.3)。包むと名前が変わるものが実装する。
 */
interface AcceptsArgumentResolver
{
    /** @param \Closure(array<string, mixed>): list<mixed> $resolver 第 2 引数以降を解決する */
    public function withArgumentResolver(\Closure $resolver): self;

    /** @param array<string, mixed> $props */
    public function __invoke(array $props = []): Element;
}
