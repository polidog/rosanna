<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Html;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\EventBinding;
use Polidog\Rosanna\Runtime\EventResolver;
use Polidog\Rosanna\Runtime\Renderable;
use Polidog\Rosanna\Runtime\ResolvesItself;

/**
 * 要素を作る API。PHP で書けない属性名はスプレッドで渡す。
 * `H::button(...['aria-label' => 'Close'], children: 'x')`
 *
 * 専用メソッドのないタグは `__callStatic()` が受ける。テストや fixture で使うものはエディタが見失わないよう宣言しておく。
 *
 * @method static Element b(mixed ...$attrs)
 * @method static Element style(mixed ...$attrs)
 * @method static Element set(mixed ...$attrs)
 * @method static Element animate(mixed ...$attrs)
 * @method static Element text(mixed ...$attrs)
 */
final class H
{
    /** defer の名前。URL のパスの 1 段になるので URL に安全な文字だけを許す */
    public const DEFER_NAME_PATTERN = '[A-Za-z0-9_-]+';

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function div(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('div', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function span(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('span', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function p(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('p', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function h1(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h1', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function h2(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h2', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function h3(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h3', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function h4(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h4', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function h5(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h5', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function h6(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h6', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function a(
        ?string $href = null,
        ?string $target = null,
        ?string $rel = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('a', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function strong(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('strong', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function em(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('em', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function small(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('small', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function mark(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('mark', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function del(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('del', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function ins(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('ins', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function sub(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('sub', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function sup(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('sup', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function code(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('code', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function pre(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('pre', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function blockquote(
        ?string $cite = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('blockquote', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function q(
        ?string $cite = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('q', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function abbr(
        ?string $title = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('abbr', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function cite(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('cite', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function time(
        ?string $datetime = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('time', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function kbd(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('kbd', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function samp(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('samp', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function varElement(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('var', get_defined_vars());
    }

    public static function br(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('br', get_defined_vars());
    }

    public static function wbr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('wbr', get_defined_vars());
    }

    public static function hr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('hr', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function ul(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('ul', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function ol(
        string|int|float|null $start = null,
        ?string $type = null,
        ?bool $reversed = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('ol', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function li(
        string|int|float|null $value = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('li', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function dl(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dl', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function dt(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dt', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function dd(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dd', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function table(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('table', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function thead(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('thead', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function tbody(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('tbody', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function tfoot(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('tfoot', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function tr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('tr', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function th(
        string|int|float|null $colspan = null,
        string|int|float|null $rowspan = null,
        ?string $scope = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('th', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function td(
        string|int|float|null $colspan = null,
        string|int|float|null $rowspan = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('td', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function caption(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('caption', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function colgroup(
        string|int|float|null $span = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('colgroup', get_defined_vars());
    }

    public static function col(
        string|int|float|null $span = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('col', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function form(
        ?string $action = null,
        ?string $method = null,
        ?string $enctype = null,
        ?string $name = null,
        ?bool $novalidate = null,
        ?string $target = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onSubmit = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('form', get_defined_vars());
    }

    public static function input(
        ?string $type = null,
        ?string $name = null,
        string|int|float|null $value = null,
        ?string $placeholder = null,
        ?bool $checked = null,
        ?bool $disabled = null,
        ?bool $readOnly = null,
        ?bool $required = null,
        string|int|float|null $min = null,
        string|int|float|null $max = null,
        string|int|float|null $minLength = null,
        string|int|float|null $maxLength = null,
        ?string $pattern = null,
        ?string $accept = null,
        ?bool $multiple = null,
        string|int|float|null $step = null,
        ?string $autocomplete = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onChange = null,
        EventBinding|\Closure|null $onInput = null,
        mixed ...$attrs,
    ): Element {
        return self::element('input', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function textarea(
        ?string $name = null,
        string|int|float|null $value = null,
        ?string $placeholder = null,
        string|int|float|null $rows = null,
        string|int|float|null $cols = null,
        ?bool $disabled = null,
        ?bool $readOnly = null,
        ?bool $required = null,
        string|int|float|null $minLength = null,
        string|int|float|null $maxLength = null,
        ?string $wrap = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onChange = null,
        EventBinding|\Closure|null $onInput = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('textarea', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function button(
        ?string $type = null,
        ?string $name = null,
        string|int|float|null $value = null,
        ?bool $disabled = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('button', get_defined_vars());
    }

    /**
     * @param list<string|int|float>|string|int|float|null $value 選ぶ option の値。`multiple` なら配列でもよい (RT-3.17)
     * @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children
     */
    public static function select(
        ?string $name = null,
        array|string|int|float|null $value = null,
        ?bool $disabled = null,
        ?bool $multiple = null,
        ?bool $required = null,
        string|int|float|null $size = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onChange = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('select', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function option(
        string|int|float|null $value = null,
        ?bool $selected = null,
        ?bool $disabled = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('option', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function optgroup(
        ?string $label = null,
        ?bool $disabled = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('optgroup', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function label(
        ?string $for = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('label', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function fieldset(
        ?bool $disabled = null,
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('fieldset', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function legend(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('legend', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function datalist(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('datalist', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function output(
        ?string $for = null,
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('output', get_defined_vars());
    }

    public static function progress(
        string|int|float|null $value = null,
        string|int|float|null $max = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('progress', get_defined_vars());
    }

    public static function meter(
        string|int|float|null $value = null,
        string|int|float|null $min = null,
        string|int|float|null $max = null,
        string|int|float|null $low = null,
        string|int|float|null $high = null,
        string|int|float|null $optimum = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('meter', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function header(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('header', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function footer(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('footer', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function nav(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('nav', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function main(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('main', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function article(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('article', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function section(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('section', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function aside(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('aside', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function figure(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('figure', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function figcaption(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('figcaption', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function details(
        ?bool $open = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('details', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function summary(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('summary', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function dialog(
        ?bool $open = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dialog', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function address(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('address', get_defined_vars());
    }

    public static function img(
        ?string $src = null,
        ?string $alt = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $loading = null,
        ?string $decoding = null,
        ?string $srcset = null,
        ?string $sizes = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('img', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function audio(
        ?string $src = null,
        ?bool $controls = null,
        ?bool $autoplay = null,
        ?bool $loop = null,
        ?bool $muted = null,
        ?string $preload = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('audio', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function video(
        ?string $src = null,
        ?string $poster = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?bool $controls = null,
        ?bool $autoplay = null,
        ?bool $loop = null,
        ?bool $muted = null,
        ?string $preload = null,
        ?bool $playsinline = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('video', get_defined_vars());
    }

    public static function source(
        ?string $src = null,
        ?string $type = null,
        ?string $srcset = null,
        ?string $sizes = null,
        ?string $media = null,
        mixed ...$attrs,
    ): Element {
        return self::element('source', get_defined_vars());
    }

    public static function track(
        ?string $src = null,
        ?string $kind = null,
        ?string $srclang = null,
        ?string $label = null,
        ?bool $default = null,
        mixed ...$attrs,
    ): Element {
        return self::element('track', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function picture(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('picture', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function canvas(
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('canvas', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function svg(
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $viewBox = null,
        ?string $fill = null,
        ?string $stroke = null,
        ?string $xmlns = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('svg', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function iframe(
        ?string $src = null,
        ?string $srcdoc = null,
        ?string $name = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $sandbox = null,
        ?string $allow = null,
        ?string $loading = null,
        ?string $referrerpolicy = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('iframe', get_defined_vars());
    }

    public static function embed(
        ?string $src = null,
        ?string $type = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('embed', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function object(
        ?string $data = null,
        ?string $type = null,
        ?string $name = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('object', get_defined_vars());
    }

    public static function param(
        ?string $name = null,
        string|int|float|null $value = null,
        mixed ...$attrs,
    ): Element {
        return self::element('param', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function map(
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('map', get_defined_vars());
    }

    public static function area(
        ?string $shape = null,
        ?string $coords = null,
        ?string $href = null,
        ?string $alt = null,
        ?string $target = null,
        ?string $rel = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('area', get_defined_vars());
    }

    public static function link(
        ?string $href = null,
        ?string $rel = null,
        ?string $type = null,
        ?string $media = null,
        ?string $sizes = null,
        ?string $crossorigin = null,
        mixed ...$attrs,
    ): Element {
        return self::element('link', get_defined_vars());
    }

    public static function meta(
        ?string $name = null,
        ?string $content = null,
        ?string $charset = null,
        ?string $httpEquiv = null,
        ?string $property = null,
        mixed ...$attrs,
    ): Element {
        return self::element('meta', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function title(
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('title', get_defined_vars());
    }

    public static function base(
        ?string $href = null,
        ?string $target = null,
        mixed ...$attrs,
    ): Element {
        return self::element('base', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function script(
        ?string $src = null,
        ?string $type = null,
        ?bool $async = null,
        ?bool $defer = null,
        ?string $crossorigin = null,
        ?string $integrity = null,
        ?bool $nomodule = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('script', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function noscript(
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('noscript', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function template(
        ?string $className = null,
        ?string $id = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('template', get_defined_vars());
    }

    /** @param array<mixed>|Element|Renderable|\Stringable|string|int|float|bool|null $children */
    public static function slot(
        ?string $name = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('slot', get_defined_vars());
    }

    /** @param array<mixed> $children */
    public static function Fragment(array $children): Element
    {
        return new Element('Fragment', [], resolveRenderables($children));
    }

    /**
     * defer のプレースホルダー。引数名は生成コードとの契約 (CP-62)。
     *
     * @param array<string, mixed> $params クエリにして送る。スカラーか null だけ
     * @throws \InvalidArgumentException 名前が URL に使えないとき
     */
    public static function defer(
        string $name,
        array $params = [],
        ?Element $fallback = null,
        bool $localCache = false,
        bool $reloadable = false,
        int $localCacheTtl = 0,
    ): Element {
        if (preg_match('/^' . self::DEFER_NAME_PATTERN . '$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Deferred component name must match `' . self::DEFER_NAME_PATTERN . "`, got: '{$name}'");
        }

        return new Element('__defer__', [
            '__name' => $name,
            '__params' => $params,
            '__fallback' => $fallback,
            '__localCache' => $localCache,
            '__reloadable' => $reloadable,
            '__localCacheTtl' => $localCacheTtl,
        ]);
    }

    /**
     * 上にないタグ。位置引数は黙って捨てず拒否する (RT-2.3)。
     *
     * @param array<int|string, mixed> $arguments
     * @throws \InvalidArgumentException
     */
    public static function __callStatic(string $name, array $arguments): Element
    {
        return self::element($name, ['attrs' => $arguments]);
    }

    /**
     * タグのメソッドの get_defined_vars() を受け、可変長の属性を props にまとめる。
     *
     * @param array<string, mixed> $vars
     * @throws \InvalidArgumentException
     */
    private static function element(string $type, array $vars): Element
    {
        /** @var array<int|string, mixed> $attrs */
        $attrs = $vars['attrs'] ?? [];
        unset($vars['attrs']);

        foreach ($attrs as $name => $value) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'H::%s() takes extra attributes as named arguments; got a positional argument.',
                    $type,
                ));
            }
            $vars[$name] = $value;
        }

        return createElement($type, $vars);
    }
}

/**
 * @param array<string, mixed> $params
 * @throws \InvalidArgumentException イベントの値か子が受けられないとき、予約の type のとき
 */
function createElement(string $type, array $params): Element
{
    // RT-1.2 これらの props はプレースホルダーの内容で、値から作らせない。H::defer() が直接作る
    if ($type === '__defer__') {
        throw new \InvalidArgumentException(sprintf('The element type %s is reserved. Use H::defer().', $type));
    }

    // textarea に value 属性はなく、値は中身のテキストである
    if ($type === 'textarea' && is_scalar($params['value'] ?? null)) {
        $params['children'] = (string) $params['value'];
        unset($params['value']);
    }

    $children = [];
    $props = [];

    foreach ($params as $name => $value) {
        // 未対応のイベントでも null は `$cond ? $action : null` の結果なので受ける (RT-2.5)
        if ($value === null) {
            continue;
        }

        switch ($name) {
            case 'onFocus':
            case 'onBlur':
            case 'onLoad':
            case 'onError':
                throw new \InvalidArgumentException(sprintf(
                    '%s is not supported: Rosanna handles events on the server. '
                    . 'Supported events are onClick, onChange, onInput and onSubmit.',
                    $name,
                ));

            case 'children':
                $children = resolveRenderables(is_array($value) ? $value : [$value]);
                break;

            case 'onClick':
            case 'onChange':
            case 'onInput':
            case 'onSubmit':
                $binding = EventResolver::resolve($name, $value);
                if ($binding !== null) {
                    $props['wire:' . strtolower(substr($name, 2))] = $binding;
                }
                break;

            default:
                $props[$name] = $value;
        }
    }

    return new Element($type, $props, $children);
}

/**
 * ネストした配列を文書順に平坦化し、コンポーネントを展開する (RT-2.8)。
 *
 * @param array<mixed> $children
 * @return list<mixed>
 */
function resolveRenderables(array $children): array
{
    $resolved = [];
    foreach ($children as $child) {
        if (is_array($child)) {
            foreach (resolveRenderables($child) as $nested) {
                $resolved[] = $nested;
            }
        } elseif ($child instanceof ResolvesItself) {
            $resolved[] = $child->resolve();
        } elseif ($child instanceof Renderable) {
            $resolved[] = $child->render();
        } else {
            $resolved[] = $child;
        }
    }

    return $resolved;
}
