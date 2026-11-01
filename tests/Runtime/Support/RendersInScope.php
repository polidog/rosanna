<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime\Support;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\Runtime\RenderScope;

/**
 * RenderScope を直接組み立てる。Rosanna を通さずに描画を確かめるため。
 */
trait RendersInScope
{
    private static function scope(?PsxRenderer $psx = null): RenderScope
    {
        return new RenderScope($psx ?? new PsxRenderer());
    }

    /** Element を作るところから同じスコープで動かす */
    private static function html(callable $build, ?RenderScope $scope = null): string
    {
        return RenderContext::run($scope ?? self::scope(), static function () use ($build): string {
            $element = $build();
            \assert($element instanceof Element || is_string($element));

            return new Renderer()->renderElement($element);
        });
    }
}
