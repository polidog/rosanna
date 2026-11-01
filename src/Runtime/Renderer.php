<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use function Polidog\Rosanna\Html\resolveRenderables;

/**
 * Element の木を HTML にする。タグ、属性、エスケープの検査はここで行う (RT-3)。
 */
final class Renderer
{
    public const TAG_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9\-]*$/';
    public const ATTRIBUTE_NAME_PATTERN = '/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/';

    private const VOID_TAGS = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true, 'hr' => true, 'img' => true,
        'input' => true, 'link' => true, 'meta' => true, 'param' => true, 'source' => true, 'track' => true, 'wbr' => true,
    ];
    private const RAW_TEXT_TAGS = ['script' => true, 'style' => true];
    /** 中身をテキストとして読み、自身の終了タグでしか閉じない要素。エスケープしない raw text がこの中にあると閉じられる (RT-3.12) */
    private const TEXT_ONLY_ANCESTORS = [
        'noscript' => true, 'textarea' => true, 'title' => true, 'xmp' => true,
        'iframe' => true, 'noembed' => true, 'noframes' => true,
    ];
    private const FOREIGN_CONTENT_ROOTS = ['svg' => true, 'math' => true];
    private const URL_ATTRIBUTES = [
        'href' => true, 'src' => true, 'action' => true, 'formaction' => true,
        'data' => true, 'poster' => true, 'background' => true, 'xlink:href' => true,
    ];
    private const ANIMATION_TAGS = ['animate' => true, 'set' => true];
    private const ANIMATION_VALUE_ATTRIBUTES = ['to' => true, 'values' => true, 'from' => true, 'by' => true];
    private const BOOLEAN_STRING_ATTRIBUTES = ['contenteditable' => true, 'draggable' => true, 'spellcheck' => true];
    private const DANGEROUS_SCHEMES = ['javascript' => true, 'vbscript' => true, 'data' => true];

    private int $foreignContentDepth = 0;

    /** @var list<string> 開いている TEXT_ONLY_ANCESTORS の要素 */
    private array $textOnlyAncestors = [];

    private int $renderDepth = 0;

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
            return self::escape($element);
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
        if (!self::matchesName(self::TAG_NAME_PATTERN, $tag)) {
            throw new \RuntimeException(sprintf('Invalid HTML tag name %s: tag names must match %s.', self::quote($tag), self::TAG_NAME_PATTERN));
        }

        $lowerTag = strtolower($tag);
        $isVoid = isset(self::VOID_TAGS[$lowerTag]);
        if ($this->extension !== null) {
            return $this->extension->renderTag($element, $lowerTag, $isVoid, $this->renderAttributes(...), $this->renderContent(...));
        }

        foreach (array_keys($element->props) as $key) {
            if (stripos((string) $key, 'wire:') === 0) {
                throw new \RuntimeException(sprintf('%s needs polidog/rosanna-state: event bindings do nothing without the state layer.', $key));
            }
        }

        $attributes = $this->renderAttributes($lowerTag, $element->props);

        return $isVoid
            ? "<{$tag}{$attributes} />"
            : "<{$tag}{$attributes}>{$this->renderContent($lowerTag, $element->children)}</{$tag}>";
    }

    /** @param array<string, mixed> $props */
    private function renderAttributes(string $lowerTag, array $props): string
    {
        $html = '';
        $animatesHref = isset(self::ANIMATION_TAGS[$lowerTag]) && self::targetsHref($props);

        foreach ($props as $key => $value) {
            $key = (string) $key;
            $name = AttributeNames::MAP[$key] ?? $key;
            self::assertValidAttributeName($name);
            $lowerName = strtolower($name);

            if (is_bool($value)) {
                if (str_starts_with($lowerName, 'aria-') || isset(self::BOOLEAN_STRING_ATTRIBUTES[$lowerName])) {
                    $html .= sprintf(' %s="%s"', $name, $value ? 'true' : 'false');
                } elseif ($value) {
                    $html .= ' ' . $name;
                }
                continue;
            }

            if (!is_scalar($value)) {
                trigger_error(sprintf('Non-scalar value for attribute "%s" (%s) was dropped.', $name, get_debug_type($value)), E_USER_NOTICE);
                continue;
            }

            $string = (string) $value;

            // RT-3.10 中身が同じオリジンの HTML になるので、スキームの検査では安全にならない
            if ($lowerName === 'srcdoc' && $string !== '') {
                throw new \RuntimeException(
                    'The srcdoc attribute is not supported because its value is rendered as HTML in the same origin. '
                    . 'Use src with a separate URL instead.',
                );
            }

            if (isset(self::URL_ATTRIBUTES[$lowerName]) && self::hasDangerousScheme($string)) {
                continue;
            }

            if ($animatesHref && isset(self::ANIMATION_VALUE_ATTRIBUTES[$lowerName]) && self::hasDangerousAnimationValue($string)) {
                continue;
            }

            $html .= sprintf(' %s="%s"', $name, self::escape($string));
        }

        return $html;
    }

    /**
     * Chromium は <set attributeName="href" to="javascript:..."> を実行する (tests/browser/svg.spec.ts)。
     * attributeName と属性名の大文字小文字はパーサーが揃えるので区別せず、接頭辞は宣言で決まるので見ない。
     *
     * @param array<string, mixed> $props
     */
    private static function targetsHref(array $props): bool
    {
        foreach ($props as $key => $value) {
            $name = (string) $key;
            if (strtolower(AttributeNames::MAP[$name] ?? $name) !== 'attributename' || !is_scalar($value)) {
                continue;
            }
            $target = strtolower(trim((string) $value, "\x00..\x20"));
            $colon = strrpos($target, ':');
            if (($colon === false ? $target : substr($target, $colon + 1)) === 'href') {
                return true;
            }
        }

        return false;
    }

    /** values は `;` 区切りで、後ろの値も時間が来れば適用される */
    private static function hasDangerousAnimationValue(string $value): bool
    {
        foreach (explode(';', $value) as $part) {
            if (self::hasDangerousScheme($part)) {
                return true;
            }
        }

        return false;
    }

    private static function assertValidAttributeName(string $name): void
    {
        if (!self::matchesName(self::ATTRIBUTE_NAME_PATTERN, $name)) {
            throw new \RuntimeException(sprintf('Invalid HTML attribute name %s: attribute names must match %s.', self::quote($name), self::ATTRIBUTE_NAME_PATTERN));
        }
        if (preg_match('/^on[a-z]/i', $name) === 1) {
            throw new \RuntimeException(sprintf(
                'Inline event handler attribute %s is not allowed. Event props (onClick, onChange, onInput, onSubmit) need polidog/rosanna-state.',
                self::quote($name),
            ));
        }
    }

    /** パターンの `$` は末尾の改行の前にも合うので、改行を別に拒否する */
    private static function matchesName(string $pattern, string $name): bool
    {
        return preg_match($pattern, $name) === 1 && !str_contains($name, "\n");
    }

    /** RT-3.11。ブラウザの URL パーサーと同じ前処理をするので `\tjavascript:` や `java\tscript:` も通さない */
    private static function hasDangerousScheme(string $url): bool
    {
        $normalized = ltrim(str_replace(["\t", "\n", "\r"], '', $url), "\x00..\x20");
        $colon = strpos($normalized, ':');
        if ($colon === false) {
            return false;
        }

        $scheme = strtolower(substr($normalized, 0, $colon));

        return preg_match('/^[a-z][a-z0-9+\-.]*$/', $scheme) === 1 && isset(self::DANGEROUS_SCHEMES[$scheme]);
    }

    /** @param array<mixed> $children */
    private function renderContent(string $lowerTag, array $children): string
    {
        if (isset(self::RAW_TEXT_TAGS[$lowerTag]) && $this->foreignContentDepth === 0) {
            return $this->renderRawText($lowerTag, $children);
        }
        if (isset(self::TEXT_ONLY_ANCESTORS[$lowerTag])) {
            $this->textOnlyAncestors[] = $lowerTag;
            try {
                return $this->renderChildren($children);
            } finally {
                array_pop($this->textOnlyAncestors);
            }
        }
        if (!isset(self::FOREIGN_CONTENT_ROOTS[$lowerTag])) {
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
        $text = self::joinRawText($children);
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
    private static function joinRawText(array $children): string
    {
        $text = '';
        foreach ($children as $child) {
            if (is_array($child)) {
                $text .= self::joinRawText($child);
            } elseif (is_string($child) || is_int($child) || is_float($child) || $child instanceof \Stringable) {
                $text .= (string) $child;
            } elseif ($child !== null && !is_bool($child)) {
                throw new \RuntimeException(sprintf('Cannot render a child of type %s inside <script> or <style>: only text is allowed.', get_debug_type($child)));
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
                $html .= self::escape((string) $child);
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
    /** ENT_SUBSTITUTE がないと、不正な UTF-8 の 1 バイトで値全体が空になる (RT-3.1) */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function quote(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
