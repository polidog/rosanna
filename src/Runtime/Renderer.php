<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use function Polidog\Rosanna\Html\resolveRenderables;
use function Polidog\Rosanna\Internal\Functions\Runtime\assertValidTagName;
use function Polidog\Rosanna\Internal\Functions\Runtime\collapseWhitespace;
use function Polidog\Rosanna\Internal\Functions\Runtime\escape;
use function Polidog\Rosanna\Internal\Functions\Runtime\firstProp;
use function Polidog\Rosanna\Internal\Functions\Runtime\isForeignContentRoot;
use function Polidog\Rosanna\Internal\Functions\Runtime\isRawTextTag;
use function Polidog\Rosanna\Internal\Functions\Runtime\isTextOnlyAncestor;
use function Polidog\Rosanna\Internal\Functions\Runtime\isVoidTag;
use function Polidog\Rosanna\Internal\Functions\Runtime\joinRawText;
use function Polidog\Rosanna\Internal\Functions\Runtime\renderAttributes;
use function Polidog\Rosanna\Internal\Functions\Runtime\resolveDeep;
use function Polidog\Rosanna\Internal\Functions\Runtime\selectValues;
use function Polidog\Rosanna\Internal\Functions\Runtime\textOf;

/**
 * Element の木を HTML にする。タグ、属性、エスケープの検査はここで行う (RT-3)。
 * 状態に触らない検査と変換は src-functions/Runtime の関数にある。
 */
final class Renderer
{
    /**
     * @deprecated 互換のために残している。検査には使っておらず、Renderer は `/D` 付きの同じ正規表現で検査する
     *             (Internal\Functions\Runtime\assertValidTagName)。この値は末尾の改行の前にも `$` が合う
     */
    public const string TAG_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9\-]*$/';

    /**
     * @deprecated 互換のために残している。検査には使っておらず、Renderer は `/D` 付きの同じ正規表現で検査する
     *             (Internal\Functions\Runtime\assertValidAttributeName)。この値は末尾の改行の前にも `$` が合う
     */
    public const string ATTRIBUTE_NAME_PATTERN = '/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/';

    private int $foreignContentDepth = 0;

    /** @var list<string> 開いている、中身をテキストとして読む要素 (isTextOnlyAncestor) */
    private array $textOnlyAncestors = [];

    private int $renderDepth = 0;

    /** @var array<array-key, true> いま開いている select の value。キーは文字列にした値 (RT-3.17) */
    private array $selectValues = [];

    public function __construct(
        private readonly ?RenderExtension $extension = null,
        private readonly ?DeferredRenderer $deferred = null,
    ) {}

    /** @throws \RuntimeException 不正な Element が届いたとき */
    public function renderElement(Element|string $element): string
    {
        $outermost = $this->renderDepth === 0;
        if ($outermost) {
            $this->extension?->beginRender();
        }

        $this->renderDepth++;
        try {
            $html = $this->renderNode($element);

            return $outermost && $this->extension !== null ? $html . $this->extension->finishRender() : $html;
        } finally {
            $this->renderDepth--;
            if ($outermost) {
                $this->extension?->endRender();
            }
        }
    }

    private function renderNode(Element|string $element): string
    {
        if (is_string($element)) {
            return escape($element);
        }

        return match ($element->type) {
            'Fragment' => $this->renderChildren($element->children),
            '__defer__' => $this->renderDeferred($element),
            default => $this->renderTag($element),
        };
    }

    private function renderDeferred(Element $element): string
    {
        if ($this->deferred === null) {
            throw new \RuntimeException('Deferred rendering (<defer> and H::defer()) needs polidog/rosanna-defer. Install it and render through RosannaDefer or RosannaState.');
        }

        return $this->deferred->renderDeferred($element, $this->renderNode(...));
    }

    private function renderTag(Element $element): string
    {
        // 補間の前に検証する。要素を黙って落とさない (RT-3.4)
        $tag = $element->type;
        assertValidTagName($tag);

        $lowerTag = strtolower($tag);
        $isVoid = isVoidTag($lowerTag);

        // RT-3.17 差し替え口にも解決した props を渡す。子はこの呼び出しの中で描画されるので、終わったら戻す
        $outerSelectValues = $this->selectValues;
        if ($lowerTag === 'select') {
            $this->selectValues = selectValues(firstProp($element->props, 'value'));
            $element = $element->withProps(array_filter($element->props, static fn(int|string $name): bool => strcasecmp((string) $name, 'value') !== 0, ARRAY_FILTER_USE_KEY));
        } elseif ($lowerTag === 'option' && $this->selectValues !== [] && firstProp($element->props, 'selected') === null) {
            $element = $this->markIfSelected($element);
        }

        try {
            return $this->renderCheckedTag($element, $tag, $lowerTag, $isVoid);
        } finally {
            $this->selectValues = $outerSelectValues;
        }
    }

    private function renderCheckedTag(Element $element, string $tag, string $lowerTag, bool $isVoid): string
    {
        if ($this->extension !== null) {
            return $this->extension->renderTag($element, $lowerTag, $isVoid, renderAttributes(...), $this->renderContent(...));
        }

        foreach (array_keys($element->props) as $key) {
            if (stripos((string) $key, 'wire:') === 0) {
                throw new \RuntimeException(sprintf('%s needs polidog/rosanna-state: event bindings do nothing without the state layer.', $key));
            }
        }

        $attributes = renderAttributes($lowerTag, $element->props);

        return $isVoid
            ? "<{$tag}{$attributes} />"
            : "<{$tag}{$attributes}>{$this->renderContent($lowerTag, $element->children)}</{$tag}>";
    }

    private function markIfSelected(Element $option): Element
    {
        $value = firstProp($option->props, 'value');
        if (!is_scalar($value) || $value === false) {
            // value 属性が出ない option の値はテキスト。調べるときに解決した子を描画にも使い、2 回解決しない
            $option = $option->withChildren(resolveDeep($option->children));
            $value = collapseWhitespace(textOf($option->children));
        }

        // 値なしの属性 (true) の値は空文字列である
        $key = $value === true ? '' : (string) $value;

        return isset($this->selectValues[$key]) ? $option->withProps([...$option->props, 'selected' => true]) : $option;
    }

    /** @param array<mixed> $children */
    private function renderContent(string $lowerTag, array $children): string
    {
        if (isRawTextTag($lowerTag) && $this->foreignContentDepth === 0) {
            return $this->renderRawText($lowerTag, $children);
        }
        if (isTextOnlyAncestor($lowerTag)) {
            $this->textOnlyAncestors[] = $lowerTag;
            try {
                return $this->renderChildren($children);
            } finally {
                array_pop($this->textOnlyAncestors);
            }
        }
        if (!isForeignContentRoot($lowerTag)) {
            return $this->renderChildren($children);
        }

        // RT-3.14 svg と math の中ではパーサーが script と style の中身をマークアップとして読む
        $this->foreignContentDepth++;
        try {
            return $this->renderChildren($children);
        } finally {
            $this->foreignContentDepth--;
        }
    }

    /**
     * パーサーがデコードしないのでエスケープせずに連結する。子に分割しても通らないよう、連結後に終了タグを検査する (RT-3.12)。
     * 祖先の RCDATA や RAWTEXT の中では style が要素にならず、中身の終了タグが祖先を閉じるので、祖先の分も検査する。
     *
     * @param array<mixed> $children
     */
    private function renderRawText(string $lowerTag, array $children): string
    {
        $text = joinRawText($children);
        if (stripos($text, '</' . $lowerTag) !== false) {
            throw new \RuntimeException(sprintf('The content of <%1$s> must not contain "</%1$s": it would close the element early.', $lowerTag));
        }
        foreach ($this->textOnlyAncestors as $ancestor) {
            if (stripos($text, '</' . $ancestor) !== false) {
                throw new \RuntimeException(sprintf('The content of <%1$s> inside <%2$s> must not contain "</%2$s": it would close the ancestor early.', $lowerTag, $ancestor));
            }
        }

        return $text;
    }

    /** @param array<mixed> $children */
    private function renderChildren(array $children): string
    {
        $html = '';
        foreach ($children as $child) {
            if ($child instanceof Element) {
                $html .= $this->renderNode($child);
            } elseif (is_array($child)) {
                $html .= $this->renderChildren($child);
            } elseif ($child instanceof Renderable) {
                $html .= $this->renderChildren(resolveRenderables([$child]));
            } elseif (is_string($child) || $child instanceof \Stringable) {
                $html .= escape((string) $child);
            } elseif (is_int($child) || is_float($child)) {
                $html .= (string) $child;
            } elseif ($child !== null && !is_bool($child)) {
                throw new \RuntimeException(sprintf(
                    'Cannot render a child of type %s. Children must be an Element, Renderable, string, number, Stringable, null, bool, or an array of those.',
                    get_debug_type($child),
                ));
            }
        }

        return $html;
    }
}
