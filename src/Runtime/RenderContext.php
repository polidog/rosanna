<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * 描画中のスコープ。リクエストをまたいで残さない (RT-9.6)。
 */
final class RenderContext
{
    private static ?RenderScope $scope = null;

    /**
     * enter と leave を例外時も含めて保証し、$fn の戻り値を返す。入れ子にできる。
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(RenderScope $scope, callable $fn): mixed
    {
        $outer = self::$scope;
        self::$scope = $scope;
        try {
            return $fn();
        } finally {
            self::$scope = $outer;
        }
    }

    /** @throws \LogicException スコープの外 */
    public static function scope(): RenderScope
    {
        return self::$scope ?? throw new \LogicException('No render scope is active. Wrap the call in RenderContext::run().');
    }

    public static function currentScope(): ?RenderScope
    {
        return self::$scope;
    }

    /** @internal RequestLifecycle::reset() のため */
    public static function clear(): void
    {
        self::$scope = null;
    }
}
