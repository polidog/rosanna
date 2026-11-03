<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** 出力の安全性 (RT-3) */
final class SecurityHardeningTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** スキームの判定の列挙は tests/Internal/Functions/Runtime/UrlsTest.php。ここは属性名の経路を見る */
    public function testRT311DangerousSchemesDropOnlyTheAttribute(): void
    {
        foreach (['href', 'HREF', 'src', 'action', 'formaction', 'formAction', 'data', 'poster', 'background', 'xlink:href', 'xlinkHref', 'XLINK:HREF'] as $name) {
            foreach (['javascript:alert(1)', "java\tscript:alert(1)"] as $url) {
                self::assertSame('<a id="i"></a>', self::html(static fn() => new Element('a', ['id' => 'i', $name => $url])), $name);
            }
        }
    }

    public function testRT311HarmlessUrlsAreEmittedUnchangedExceptEscaping(): void
    {
        self::assertSame('<a href="https://example.com/?a=1&amp;b=2"></a>', self::html(static fn() => H::a(href: 'https://example.com/?a=1&b=2')));
        self::assertSame('<a href="#javascript:x"></a>', self::html(static fn() => H::a(href: '#javascript:x')));
        self::assertSame("<a href=\"/a\tb\"></a>", self::html(static fn() => H::a(href: "/a\tb")));
    }

    public function testRT311OtherAttributesAreNotInspected(): void
    {
        self::assertSame('<a title="javascript:x"></a>', self::html(static fn() => H::a(title: 'javascript:x')));
    }

    /** @return iterable<string, array{string}> */
    public static function rawEndTags(): iterable
    {
        yield 'script' => ['script'];
        yield 'style' => ['style'];
        yield 'upper tag' => ['SCRIPT'];
    }

    #[DataProvider('rawEndTags')]
    public function testRT312SplitEndTagIsRefusedAfterJoining(string $tag): void
    {
        $lower = strtolower($tag);
        foreach ([["</{$lower}>"], ['</', $lower, '>'], ['<', "/{$lower}"], ['x</' . strtoupper($lower)], [['</'], [[$lower]]]] as $children) {
            try {
                self::html(static fn() => new Element($tag, [], $children));
                self::fail('Expected a refusal for ' . json_encode($children));
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('must not contain', $e->getMessage());
            }
        }
    }

    public function testRT312RawTextIsNotEscaped(): void
    {
        self::assertSame('<script>if (a < b && c > "d") { e(); }</script>', self::html(static fn() => H::script(children: 'if (a < b && c > "d") { e(); }')));
        self::assertSame('<STYLE>a > b {}</STYLE>', self::html(static fn() => new Element('STYLE', [], ['a > b {}'])));
    }

    public function testRT314ForeignContentEscapesAndRecovers(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        $html = self::html(static fn() => H::Fragment([
            new Element('svg', [], [new Element('style', [], [$payload]), new Element('script', [], [$payload])]),
            new Element('style', [], ['a > b {}']),
        ]));

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<svg><style>&lt;img src=x onerror=alert(1)&gt;</style><script>&lt;img', $html);
        self::assertStringEndsWith('<style>a > b {}</style>', $html);
    }

    public function testRT314NestedForeignContentKeepsCountingUntilTheOutermostCloses(): void
    {
        $inner = new Element('math', [], [new Element('style', [], ['<i>'])]);
        $html = self::html(static fn() => H::Fragment([
            new Element('svg', [], [$inner, new Element('style', [], ['<b>'])]),
            new Element('SCRIPT', [], ['1<2']),
            new Element('MATH', [], [new Element('style', [], ['<u>'])]),
        ]));

        self::assertStringContainsString('<math><style>&lt;i&gt;</style></math><style>&lt;b&gt;</style></svg>', $html);
        self::assertStringContainsString('<SCRIPT>1<2</SCRIPT>', $html);
        self::assertStringContainsString('<MATH><style>&lt;u&gt;</style></MATH>', $html);
    }

    public function testRT314ThrowInForeignContentDoesNotLeakTheDepth(): void
    {
        $renderer = new Renderer();
        try {
            $renderer->renderElement(new Element('svg', [], [new \stdClass()]));
            self::fail();
        } catch (\RuntimeException) {
        }

        self::assertSame('<style><b></style>', $renderer->renderElement(new Element('style', [], ['<b>'])));
    }

    /** 名前の列挙は tests/Internal/Functions/Runtime/AttributesTest.php。ここは変換の後に検査することを見る */
    public function testRT38InvalidAttributeNameThrows(): void
    {
        foreach (['a" onclick="x', "a\n"] as $name) {
            try {
                self::html(static fn() => new Element('div', [$name => 'x']));
                self::fail('Expected a refusal for ' . json_encode($name));
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Invalid HTML attribute name', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function inlineHandlers(): iterable
    {
        yield 'lower' => ['onclick', 'x()'];
        yield 'error' => ['onerror', 'x()'];
        yield 'camel' => ['onMouseOver', 'x()'];
        yield 'upper' => ['ONCLICK', 'x()'];
        yield 'bool true' => ['onload', true];
        yield 'bool false' => ['onload', false];
        yield 'number' => ['onload', 1];
        yield 'array' => ['onload', ['x']];
    }

    #[DataProvider('inlineHandlers')]
    public function testRT38InlineHandlersThrowWhateverTheValue(string $name, mixed $value): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler');
        self::html(static fn() => new Element('div', [$name => $value]));
    }

    public function testRT38DataAriaAndNamespacedNamesPass(): void
    {
        self::assertSame('<svg data-x="1" aria-label="a" xml:lang="ja" viewBox="0 0 1 1"></svg>', self::html(static fn() => new Element('svg', [
            'data-x' => '1', 'aria-label' => 'a', 'xml:lang' => 'ja', 'viewBox' => '0 0 1 1',
        ])));
    }
}
