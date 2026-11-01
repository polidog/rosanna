<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * worker モードで別の訪問者に状態が漏れないよう、リクエストの間で呼ぶ (RT-9.6)。
 * Memory と Snapshot の値はインスタンスと一緒に消える。Storage に static はない。
 */
final class RequestLifecycle
{
    public static function reset(): void
    {
        ComponentState::clearInstances();
        RenderContext::clear();
    }
}
