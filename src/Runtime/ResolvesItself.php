<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * children に置くと `resolveRenderables()` が `resolve()` で展開するもの。
 * `render()` と違い、ラッパーなどの付いた最終形を返す。
 */
interface ResolvesItself extends Renderable
{
    public function resolve(): Element;
}
