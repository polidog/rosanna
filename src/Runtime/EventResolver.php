<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * `onClick` などの値を `EventBinding` にする口。既定はクロージャを実行せずに拒否し、状態層が実装を差し込む。
 * リクエストごとの情報を持たないので worker でも問題ない。
 */
final class EventResolver
{
    /** @var (\Closure(string, mixed): ?EventBinding)|null */
    private static ?\Closure $resolver = null;

    /**
     * phpactor は `?EventBinding` のような戻り値型を読めないので、正確な型は @psalm-param に書く
     *
     * @param \Closure $resolver
     * @psalm-param \Closure(string, mixed): ?EventBinding $resolver
     */
    public static function register(\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param string $event `onClick` などの prop の名前
     * @param mixed $value null でない値
     * @throws \InvalidArgumentException 受けられない値のとき
     */
    public static function resolve(string $event, mixed $value): ?EventBinding
    {
        if (self::$resolver === null) {
            throw new \InvalidArgumentException(sprintf('%s needs polidog/rosanna-state: event props do nothing without the state layer.', $event));
        }

        return (self::$resolver)($event, $value);
    }
}
