<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * worker モードで別の訪問者に状態が漏れないよう、リクエストの間で呼ぶ (RT-9.6)。
 * 呼ぶのは `reset()` だけにする。層ごとに分けると呼び忘れで漏れる。
 */
final class RequestLifecycle
{
    /** @var array<string, \Closure(): void> */
    private static array $callbacks = [];

    /**
     * `reset()` で一緒に消すものを登録する。同じ名前なら置き換える (autoload が 2 回読まれても増えない)。
     *
     * @param \Closure(): void $callback
     */
    public static function onReset(string $name, \Closure $callback): void
    {
        self::$callbacks[$name] = $callback;
    }

    public static function reset(): void
    {
        RenderContext::clear();
        foreach (self::$callbacks as $callback) {
            $callback();
        }
    }
}
