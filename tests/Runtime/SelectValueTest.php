<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;
use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\Runtime\RenderExtension;

/** RT-3.17 */
final class SelectValueTest extends TestCase
{
    private static function html(Element $element): string
    {
        return new Renderer()->renderElement($element);
    }

    public function testRT317ValueMarksTheMatchingOptionInsteadOfBecomingAnAttribute(): void
    {
        self::assertSame(
            '<select name="plan"><option value="a">A</option><option value="b" selected>B</option></select>',
            self::html(H::select(name: 'plan', value: 'b', children: [
                H::option(value: 'a', children: 'A'),
                H::option(value: 'b', children: 'B'),
            ])),
        );
    }

    public function testRT317OptionsInsideOptgroupAreMarked(): void
    {
        self::assertSame(
            '<select><optgroup label="x"><option value="a">A</option></optgroup><optgroup label="y"><option value="b" selected>B</option></optgroup></select>',
            self::html(H::select(value: 'b', children: [
                H::optgroup(label: 'x', children: H::option(value: 'a', children: 'A')),
                H::optgroup(label: 'y', children: H::option(value: 'b', children: 'B')),
            ])),
        );
    }

    public function testRT317ValuesAreComparedAsStrings(): void
    {
        self::assertSame(
            '<select><option value="1" selected></option><option value="01"></option><option value="1.0"></option></select>',
            self::html(H::select(value: 1, children: [H::option(value: '1'), H::option(value: '01'), H::option(value: '1.0')])),
        );
        self::assertSame(
            '<select><option value="1" selected></option></select>',
            self::html(H::select(value: '1', children: H::option(value: 1))),
        );
        // PHP の緩い比較なら合ってしまう組み合わせ
        self::assertSame(
            '<select><option value="1e1"></option><option value="abc"></option></select>',
            self::html(H::select(value: '10', children: [H::option(value: '1e1'), H::option(value: 'abc')])),
        );
    }

    public function testRT317EmptyStringMatchesOnlyAnEmptyValue(): void
    {
        self::assertSame(
            '<select><option value="" selected>none</option><option value="0">zero</option></select>',
            self::html(H::select(value: '', children: [H::option(value: '', children: 'none'), H::option(value: '0', children: 'zero')])),
        );
        self::assertSame(
            '<select><option value="">none</option><option value="0" selected>zero</option></select>',
            self::html(H::select(value: 0, children: [H::option(value: '', children: 'none'), H::option(value: '0', children: 'zero')])),
        );
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function textValues(): iterable
    {
        yield 'text' => ['Plan B', '<option selected>Plan B</option>'];
        yield 'whitespace collapses' => [" \n Plan \t  B\n", "<option selected> \n Plan \t  B\n</option>"];
        yield 'split children' => [['Plan ', 'B'], '<option selected>Plan B</option>'];
        yield 'number' => [[7], '<option>7</option>'];
        yield 'element' => [[H::b(children: 'Plan'), ' B'], '<option selected><b>Plan</b> B</option>'];
        yield 'fragment' => [[H::Fragment(['Plan', ' B'])], '<option selected>Plan B</option>'];
        yield 'renderable' => [[self::renderable(H::Fragment(['Plan B']))], '<option selected>Plan B</option>'];
        yield 'stringable' => [[new class implements \Stringable {
            public function __toString(): string
            {
                return 'Plan B';
            }
        }], '<option selected>Plan B</option>'];
        yield 'null and bool' => [['Plan B', null, true, false], '<option selected>Plan B</option>'];
        yield 'other text' => ['Plan C', '<option>Plan C</option>'];
    }

    /** HTML では value 属性のない option の値は、空白を詰めたテキストである */
    #[DataProvider('textValues')]
    public function testRT317OptionWithoutValueUsesItsText(mixed $children, string $expected): void
    {
        self::assertSame(
            "<select>{$expected}</select>",
            self::html(new Element('select', ['value' => 'Plan B'], [new Element('option', [], is_array($children) ? $children : [$children])])),
        );
    }

    public function testRT317TextIsDecodedTheWayTheBrowserSeesIt(): void
    {
        self::assertSame(
            '<select><option selected>a &amp; b</option></select>',
            self::html(H::select(value: 'a & b', children: H::option(children: 'a & b'))),
        );
    }

    public function testRT317ValueAttributeTakesPriorityOverText(): void
    {
        self::assertSame(
            '<select><option value="x">b</option><option value="b" selected>x</option></select>',
            self::html(H::select(value: 'b', children: [H::option(value: 'x', children: 'b'), H::option(value: 'b', children: 'x')])),
        );
    }

    public function testRT317EmptyValueAttributeDoesNotFallBackToText(): void
    {
        self::assertSame(
            '<select><option value="">b</option></select>',
            self::html(H::select(value: 'b', children: H::option(value: '', children: 'b'))),
        );
    }

    public function testRT317ExplicitSelectedOnTheOptionWins(): void
    {
        // 値に合わない option の selected: true は残り、合う option にも付く。単一選択では文書順の最後をブラウザが選ぶ
        self::assertSame(
            '<select><option value="" selected>choose</option><option value="b" selected>B</option></select>',
            self::html(H::select(value: 'b', children: [H::option(value: '', selected: true, children: 'choose'), H::option(value: 'b', children: 'B')])),
        );
        // 値に合う option でも selected: false なら付けない
        self::assertSame(
            '<select><option value="b">B</option></select>',
            self::html(H::select(value: 'b', children: H::option(value: 'b', selected: false, children: 'B'))),
        );
    }

    public function testRT317NullSelectedIsNotExplicit(): void
    {
        self::assertSame(
            '<select><option value="b" selected>B</option></select>',
            self::html(H::select(value: 'b', children: H::option(value: 'b', selected: null, children: 'B'))),
        );
    }

    public function testRT317MultipleWithArrayValue(): void
    {
        self::assertSame(
            '<select multiple><option value="1" selected></option><option value="2"></option><option value="b" selected></option></select>',
            self::html(H::select(multiple: true, value: [1, 'b'], children: [H::option(value: '1'), H::option(value: '2'), H::option(value: 'b')])),
        );
    }

    public function testRT317NoMatchSelectsNothing(): void
    {
        self::assertSame(
            '<select><option value="a">A</option></select>',
            self::html(H::select(value: 'z', children: H::option(value: 'a', children: 'A'))),
        );
    }

    public function testRT317ChildrenThatAreArraysFragmentsAndRenderables(): void
    {
        $expected = '<select><option value="a"></option><option value="b" selected></option></select>';
        $options = [H::option(value: 'a'), H::option(value: 'b')];

        self::assertSame($expected, self::html(H::select(value: 'b', children: [[$options]])));
        self::assertSame($expected, self::html(H::select(value: 'b', children: H::Fragment($options))));
        self::assertSame($expected, self::html(H::select(value: 'b', children: self::renderable(H::Fragment($options)))));
        // createElement を通らず、Renderer が遅れて解決する子
        self::assertSame($expected, self::html(new Element('select', ['value' => 'b'], [[self::renderable(H::Fragment([$options]))]])));
    }

    public function testRT317OptionRenderableIsRenderedOnce(): void
    {
        $count = 0;
        $child = new class ($count) implements Renderable {
            public function __construct(private int &$count) {}

            public function render(): Element
            {
                $this->count++;

                return H::Fragment(['b']);
            }
        };

        self::assertSame(
            '<select><option selected>b</option></select>',
            self::html(new Element('select', ['value' => 'b'], [new Element('option', [], [$child])])),
        );
        self::assertSame(1, $count);

        // Element の中の子も、値を調べるときと描画するときで 2 回解決しない
        self::assertSame(
            '<select><option selected><b>b</b></option></select>',
            self::html(new Element('select', ['value' => 'b'], [new Element('option', [], [new Element('b', [], [$child])])])),
        );
        self::assertSame(2, $count);
    }

    public function testRT317NamesAreCaseInsensitive(): void
    {
        self::assertSame(
            '<SELECT><OPTION VALUE="b" selected></OPTION></SELECT>',
            self::html(new Element('SELECT', ['VALUE' => 'b'], [new Element('OPTION', ['VALUE' => 'b'])])),
        );
        self::assertSame(
            '<select><option value="b" SELECTED></option><option value="a" SELECTED></option></select>',
            self::html(new Element('select', ['Value' => 'b'], [new Element('option', ['value' => 'b', 'SELECTED' => true]), new Element('option', ['value' => 'a', 'SELECTED' => true])])),
        );
        self::assertSame(
            '<select><option value="b" SELECTED="SELECTED"></option></select>',
            self::html(new Element('select', ['value' => 'b'], [new Element('option', ['value' => 'b', 'SELECTED' => 'SELECTED'])])),
        );
        self::assertSame(
            '<select><option value="b"></option></select>',
            self::html(new Element('select', ['value' => 'b'], [new Element('option', ['value' => 'b', 'SELECTED' => false])])),
        );
    }

    public function testRT317ValueIsNotAnAttributeInAnyCaseAndTheFirstOneCounts(): void
    {
        // HTML パーサーと同じく、同じ名前の属性は最初のものを使う
        self::assertSame(
            '<select><option value="a" selected></option><option value="b"></option></select>',
            self::html(new Element('select', ['VALUE' => 'a', 'value' => 'b'], [new Element('option', ['value' => 'a']), new Element('option', ['value' => 'b'])])),
        );
    }

    public function testRT317SelectWithoutValueLeavesOptionsAlone(): void
    {
        self::assertSame(
            '<select><option value="a"></option><option value="b" selected></option></select>',
            self::html(H::select(children: [H::option(value: 'a'), H::option(value: 'b', selected: true)])),
        );
    }

    public function testRT317ValueDoesNotReachOptionsOutsideTheSelect(): void
    {
        self::assertSame(
            '<div><select><option value="b" selected></option></select><select><option value="b"></option></select><datalist><option value="b"></option></datalist></div>',
            self::html(H::div(children: [
                H::select(value: 'b', children: H::option(value: 'b')),
                H::select(children: H::option(value: 'b')),
                H::datalist(children: H::option(value: 'b')),
            ])),
        );
    }

    public function testRT317NestedSelectUsesItsOwnValue(): void
    {
        self::assertSame(
            '<select><select><option value="b"></option></select><option value="b" selected></option></select>',
            self::html(new Element('select', ['value' => 'b'], [new Element('select', [], [new Element('option', ['value' => 'b'])]), new Element('option', ['value' => 'b'])])),
        );
    }

    public function testRT317ValueDoesNotOutliveAFailedRender(): void
    {
        $renderer = new Renderer();
        try {
            $renderer->renderElement(new Element('select', ['value' => 'b'], [new \stdClass()]));
            self::fail('The child should have been rejected.');
        } catch (\RuntimeException) {
        }

        self::assertSame('<option value="b"></option>', $renderer->renderElement(H::option(value: 'b')));
    }

    /** 値の列挙は tests/Internal/Functions/Runtime/AttributesTest.php の testSelectValues で見る */
    public function testRT317BoolValueSelectsNothing(): void
    {
        self::assertSame(
            '<select><option value="1"></option><option value=""></option></select>',
            self::html(new Element('select', ['value' => true], [new Element('option', ['value' => '1']), new Element('option', ['value' => ''])])),
        );
    }

    public function testRT317NonScalarValueIsDroppedWithANotice(): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = [$level, $message];

            return true;
        });
        try {
            $html = self::html(H::select(multiple: true, value: ['a', ['b']], children: [H::option(value: 'a'), H::option(value: 'b')]));
        } finally {
            restore_error_handler();
        }

        self::assertSame('<select multiple><option value="a" selected></option><option value="b"></option></select>', $html);
        self::assertSame([[E_USER_NOTICE, 'Non-scalar value for attribute "value" (array) was dropped.']], $notices);
    }

    /** rosanna-state の ActionFormExtension と同じく、渡されたクロージャで描画する */
    public function testRT317WorksThroughARenderExtension(): void
    {
        $extension = new class implements RenderExtension {
            /** @var array<string, array<string, mixed>> */
            public array $props = [];

            public function beginRender(): void {}

            public function finishRender(): string
            {
                return '';
            }

            public function endRender(): void {}

            public function renderTag(Element $element, string $lowerTag, bool $isVoid, \Closure $attributes, \Closure $content): string
            {
                $this->props[$lowerTag] = $element->props;
                $tag = $element->type;

                return "<{$tag}{$attributes($lowerTag, $element->props)}>{$content($lowerTag, $element->children)}</{$tag}>";
            }
        };

        self::assertSame(
            '<select name="plan"><optgroup><option value="b" selected>B</option></optgroup></select>',
            new Renderer($extension)->renderElement(H::select(name: 'plan', value: 'b', children: H::optgroup(children: H::option(value: 'b', children: 'B')))),
        );
        self::assertSame(['name' => 'plan'], $extension->props['select']);
        self::assertSame(['value' => 'b', 'selected' => true], $extension->props['option']);
    }

    private static function renderable(Element $element): Renderable
    {
        return new class ($element) implements Renderable {
            public function __construct(private readonly Element $element) {}

            public function render(): Element
            {
                return $this->element;
            }
        };
    }
}
