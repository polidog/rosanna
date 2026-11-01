<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Storage\NativeSession;

/**
 * worker モードで別の訪問者に状態が漏れないよう、リクエストの間で呼ぶ (RT-9.6)。
 * Memory と Snapshot の値はインスタンスと一緒に消える。Storage に static はない。
 * セッションも閉じる。開いたままだと次の訪問者が前の訪問者のセッションを読み書きする。
 */
final class RequestLifecycle
{
    public static function reset(): void
    {
        ComponentState::clearInstances();
        RenderContext::clear();
        NativeSession::release();
    }
}
