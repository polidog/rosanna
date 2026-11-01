<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;

/** RT-12 の描画だけの部分 */
final class RosannaTest extends TestCase
{
    public function testRT123RenderElementWithoutTheStateLayer(): void
    {
        self::assertSame('<p>x</p>', new Rosanna()->renderElement(H::p(children: 'x')));
        self::assertNull(RenderContext::currentScope());
    }

    public function testRT128ScopeTakesNoRequestAndHoldsNoState(): void
    {
        $app = new Rosanna()->registerComponent('App\\C', static fn(array $props): Element => H::b(children: $props['v']));
        $scope = $app->scope();

        self::assertNull($scope->state);
        self::assertSame('<b>x</b>', RenderContext::run($scope, static fn(): string => $app->renderElement(RenderContext::scope()->psx->render('App\\C', ['v' => 'x']))));
    }

    /** RT-12.9 同じアプリのスコープの中なら使い回す */
    public function testRT129InnerRenderUsesTheOuterScope(): void
    {
        $app = new Rosanna();
        $scope = $app->scope();

        RenderContext::run($scope, static function () use ($app, $scope): void {
            $app->inScope(static fn(): mixed => self::assertSame($scope, RenderContext::scope()));
            new Rosanna()->inScope(static fn(): mixed => self::assertNotSame($scope, RenderContext::scope()));
            self::assertSame($scope, RenderContext::scope());
        });
    }

    /** CP-2 01 の 11 節 本体からコンパイラを参照しない */
    public function testTheRuntimeDoesNotMentionTheCompiler(): void
    {
        self::assertStringNotContainsString('Psx\\', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Rosanna.php'));
    }
}
