<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use function Polidog\Rosanna\Html\resolveEventAction;

use Polidog\Rosanna\Storage\NativeSession;

// Composer の autoload.files が読む。リクエストごとの情報を持たないので worker でも問題ない
EventResolver::register(static fn(string $event, mixed $value): ?EventBinding => resolveEventAction($event, $value));
RequestLifecycle::onReset('rosanna-state', static function (): void {
    ComponentState::clearInstances();
    NativeSession::release();
});
