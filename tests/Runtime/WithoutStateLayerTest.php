<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\createElement;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Psx\Compiler;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\EventBinding;
use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/**
 * 状態層なしで、状態層の機能を使うと `polidog/rosanna-state` を案内する例外になる。
 * このリポジトリの autoload には状態層がないので、そのまま試せる。
 */
final class WithoutStateLayerTest extends TestCase
{
    private const string GUIDE = 'polidog/rosanna-state';
    private const string DEFER_GUIDE = 'polidog/rosanna-defer';

    public static int $invoked = 0;

    protected function setUp(): void
    {
        self::$invoked = 0;
    }

    public function testRendererWorksWithoutArguments(): void
    {
        self::assertSame('<p class="a">x &amp; y</p>', new Renderer()->renderElement(H::p(className: 'a', children: 'x & y')));
        self::assertSame('&lt;b&gt;', new Renderer()->renderElement('<b>'));
    }

    /** @return iterable<string, array{string}> */
    public static function eventProps(): iterable
    {
        yield 'onClick' => ['onClick'];
        yield 'onChange' => ['onChange'];
        yield 'onInput' => ['onInput'];
        yield 'onSubmit' => ['onSubmit'];
    }

    #[DataProvider('eventProps')]
    public function testEventPropGuidesToTheStateLayerWithoutRunningAClosure(string $event): void
    {
        try {
            createElement('button', [$event => static function (): void {
                self::$invoked++;
            }]);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(self::GUIDE, $e->getMessage());
            self::assertStringContainsString($event, $e->getMessage());
        }
        self::assertSame(0, self::$invoked);
    }

    public function testTagMethodOnClickGuidesToTheStateLayerWithoutRunningAClosure(): void
    {
        foreach (['button', 'div', 'p'] as $tag) {
            try {
                H::{$tag}(onClick: static function (): void {
                    self::$invoked++;
                });
                self::fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString(self::GUIDE, $e->getMessage());
            }
        }
        self::assertSame(0, self::$invoked);
    }

    /** `EventBinding` の値も、受ける実装がなければ拒否する */
    public function testBindingValueGuidesToTheStateLayer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::GUIDE);
        H::button(onClick: new class implements EventBinding {});
    }

    /** RT-2.5 `$cond ? $action : null` の null は受ける */
    public function testNullEventPropIsIgnored(): void
    {
        self::assertSame('<button>x</button>', new Renderer()->renderElement(H::button(onClick: null, children: 'x')));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function wireProps(): iterable
    {
        yield 'lower' => ['wire:click', 'x'];
        yield 'upper' => ['WIRE:CLICK', 'x'];
        yield 'closure' => ['wire:submit', static function (): void {
            self::$invoked++;
        }];
        yield 'true' => ['wire:input', true];
        yield 'null' => ['wire:change', null];
    }

    /** 既定はすべて例外にする。属性として出力しない */
    #[DataProvider('wireProps')]
    public function testWirePropGuidesToTheStateLayer(string $name, mixed $value): void
    {
        try {
            new Renderer()->renderElement(new Element('button', [$name => $value]));
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(self::GUIDE, $e->getMessage());
        }
        self::assertSame(0, self::$invoked);
    }

    public function testNestedWirePropGuidesToTheStateLayer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::GUIDE);
        new Rosanna()->renderElement(H::div(children: [new Element('button', ['wire:click' => 'x'])]));
    }

    /** RT-1.2 値を作るだけなので、描画するまでは例外にならない */
    public function testDeferBuildsTheElementAndRenderingGuidesToTheDeferPackage(): void
    {
        $element = H::defer('panel');
        self::assertSame('__defer__', $element->type);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::DEFER_GUIDE);
        new Rosanna()->renderElement($element);
    }

    public function testDeferTagInPsxGuidesToTheDeferPackage(): void
    {
        $dir = TemporaryDirectory::create('psx-no-state-');
        try {
            $file = $dir . '/a.php';
            \file_put_contents($file, new Compiler()->compile('<?php return fn () => <div><defer name="a" /></div>;'));
            $component = require $file;
            \assert($component instanceof \Closure);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(self::DEFER_GUIDE);
            new Rosanna()->renderElement($component());
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    /** defer の差し替え口は状態層の差し替え口と独立している */
    public function testDeferredRendererWorksWithoutARenderExtension(): void
    {
        $deferred = new class implements \Polidog\Rosanna\Runtime\DeferredRenderer {
            public function renderDeferred(\Polidog\Rosanna\Runtime\Element $element, \Closure $render): string
            {
                return '<placeholder>';
            }
        };

        self::assertSame('<div><placeholder></div>', new \Polidog\Rosanna\Runtime\Renderer(deferred: $deferred)->renderElement(H::div(children: [H::defer('a')])));
    }

    public function testDeferNameIsValidatedWithoutTheStateLayer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::defer('../bad');
    }
}
