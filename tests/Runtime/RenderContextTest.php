<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-8、RT-9 */
final class RenderContextTest extends TestCase
{
    use RendersInScope;

    protected function setUp(): void
    {
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT91ScopeOutsideRunIsALogicException(): void
    {
        self::assertNull(RenderContext::currentScope());
        $this->expectException(\LogicException::class);
        RenderContext::scope();
    }

    public function testRT92RunReturnsAndRestoresWhenNested(): void
    {
        $outer = self::scope();
        $inner = self::scope();

        $result = RenderContext::run($outer, function () use ($outer, $inner): string {
            self::assertSame($outer, RenderContext::scope());
            $value = RenderContext::run($inner, function () use ($inner): string {
                self::assertSame($inner, RenderContext::scope());

                return 'value';
            });
            self::assertSame($outer, RenderContext::scope());

            return $value;
        });

        self::assertSame('value', $result);
        self::assertNull(RenderContext::currentScope());
    }

    /** RT-9.6 呼ぶのは `reset()` だけで、登録されたものも一緒に消す */
    public function testRT96ResetClearsTheScopeAndRunsTheRegisteredCallbacks(): void
    {
        $calls = 0;
        RequestLifecycle::onReset('render-context-test', static function () use (&$calls): void {
            $calls++;
        });
        // 同じ名前なら置き換える (autoload が 2 回読まれても増えない)
        RequestLifecycle::onReset('render-context-test', static function () use (&$calls): void {
            $calls += 10;
        });

        RenderContext::run(self::scope(), static function (): void {
            RequestLifecycle::reset();
            self::assertNull(RenderContext::currentScope());
        });

        self::assertSame(10, $calls);
        RequestLifecycle::onReset('render-context-test', static function (): void {});
    }

    public function testRT92ExceptionRestoresTheOuterScope(): void
    {
        $outer = self::scope();
        RenderContext::run($outer, function () use ($outer): void {
            try {
                RenderContext::run(self::scope(), static function (): never {
                    throw new \DomainException('boom');
                });
            } catch (\DomainException) {
            }
            self::assertSame($outer, RenderContext::currentScope());
        });
        self::assertNull(RenderContext::currentScope());
    }
}
