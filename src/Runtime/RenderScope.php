<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * 描画中の ambient。`RenderContext::run()` の間だけ有効で、リクエストごとに作る。
 * 生成コードは `RenderContext::scope()->psx->render` を呼ぶ (CP-62)。
 */
final class RenderScope
{
    /** @param object|null $state 状態層の値を置く場所。本体は中身を読まない */
    public function __construct(
        public readonly PsxRenderer $psx,
        public readonly ?object $state = null,
    ) {}
}
