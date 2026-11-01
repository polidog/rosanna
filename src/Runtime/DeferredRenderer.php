<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * 予約の type `__defer__` を HTML にする差し替え口。`RenderExtension` と独立しているので、
 * 状態層なしで defer だけを使える。実装は `polidog/rosanna-defer` が持つ。
 */
interface DeferredRenderer
{
    /**
     * @param \Closure(Element): string $render 中の Element を `Renderer` で描画する
     */
    public function renderDeferred(Element $element, \Closure $render): string;
}
