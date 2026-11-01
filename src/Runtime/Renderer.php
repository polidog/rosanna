<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Component\Defer;

use function Polidog\Rosanna\Html\resolveRenderables;

use Polidog\Rosanna\Protocol\ActionEnvelope;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

/**
 * Element の木を HTML にする。`new` せず RendererFactory から得る (RT-9.5)。
 */
final class Renderer
{
    public const TAG_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9\-]*$/';
    public const ATTRIBUTE_NAME_PATTERN = '/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/';
    public const DEFAULT_DEFER_PREFIX = '/_defer';

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

    private int $openFormDepth = 0;

    /**
     * まだ書き出していないフォーム。要素の隣に出すと form、p、tbody の中でマークアップが崩れるので溜める (RT-4.5)。
     *
     * @var list<ActionForm>
     */
    private array $pendingForms = [];

    /**
     * いまのスコープの共有フォーム。スコープは最も内側の `data-rosanna` 要素か、最も外側の renderElement() (RT-4.7)。
     * 書き出した後も使い回すので、スコープの間は消さない (RT-4.8)。
     *
     * @var array<string, ActionForm> action の対象のインスタンス ID => 共有フォーム
     */
    private array $sharedForms = [];

    /** @internal RendererFactory を使う */
    public function __construct(
        private readonly string $rootId,
        private readonly ?StorageType $storageType,
        private readonly ?SnapshotPersist $persist,
        private readonly ?string $deferQuery,
        private readonly ?SnapshotSerializer $snapshotSerializer,
        private readonly ActionSigner $signer,
        private readonly CsrfGuard $csrf,
        private readonly string $deferPrefix,
    ) {}

    /**
     * ラッパー付き。
     *
     * @param callable(): mixed $component
     * @throws \RuntimeException 不正な Element が届いたとき
     * @throws \LogicException 秘密鍵なしの Snapshot (SN-10)
     */
    public function render(callable $component): string
    {
        $parentState = ComponentState::current();
        try {
            $state = ComponentState::getInstance($this->rootId, $this->storageType);
            ComponentState::reset();
            $result = $component();
            $props = ComponentWrapper::props($state, $this->persist, $this->snapshotSerializer, $this->signer);

            return $this->renderElement(new Element('div', $props, [$result]));
        } finally {
            ComponentState::setCurrent($parentState);
        }
    }

    /**
     * ラッパーなし (部分更新)。Snapshot では末尾に新しいスナップショットの hidden を付ける (SN-12)。
     *
     * @param callable(): mixed $component
     * @throws \RuntimeException 不正な Element が届いたとき
     * @throws \LogicException 秘密鍵なしの Snapshot (SN-10)
     */
    public function renderPartial(callable $component): string
    {
        $parentState = ComponentState::current();
        try {
            $state = ComponentState::getInstance($this->rootId, $this->storageType);
            ComponentState::reset();
            $html = $this->renderElement(new Element('Fragment', [], [$component()]));
            if ($state->isSnapshotStorage()) {
                $html .= self::hiddenField(
                    '_rosanna_snapshot',
                    ComponentWrapper::serialize($state, $this->snapshotSerializer),
                    ' data-rosanna-snapshot-update',
                );
            }

            return $html;
        } finally {
            ComponentState::setCurrent($parentState);
        }
    }

    /** @throws \RuntimeException 不正な Element が届いたとき */
    public function renderElement(Element|string $element): string
    {
        $outermost = $this->renderDepth === 0;
        if ($outermost) {
            $this->sharedForms = [];
            $this->pendingForms = [];
        }

        $this->renderDepth++;
        try {
            $html = $this->renderNode($element);

            return $outermost ? $html . $this->flushForms() : $html;
        } finally {
            $this->renderDepth--;
            if ($outermost) {
                $this->sharedForms = [];
                $this->pendingForms = [];
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
            '__function_component__' => throw new \RuntimeException(
                'Function component Element reached the renderer unresolved. '
                . 'Place H::component(...) inside a children array so it is expanded before rendering.',
            ),
            default => $this->renderTag($element),
        };
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
        if ($isVoid || !isset($element->props['data-rosanna'])) {
            return $this->renderTagBody($element, $lowerTag, $isVoid, false);
        }

        // 部分更新で親のフォームが消えないよう、外で溜まっていたものは持ち込まない (RT-4.5)
        // form 自身がスコープのときも、中に入れると入れ子になるので後ろに出す (RT-4.5)
        $ownsForms = $this->openFormDepth === 0 && $lowerTag !== 'form';
        $outerForms = $this->pendingForms;
        if ($ownsForms) {
            $this->pendingForms = [];
        }
        $outerSharedForms = $this->sharedForms;
        $this->sharedForms = [];

        try {
            return $this->renderTagBody($element, $lowerTag, false, $ownsForms);
        } finally {
            $this->sharedForms = $outerSharedForms;
            if ($ownsForms) {
                $this->pendingForms = $outerForms;
            }
        }
    }

    private function renderTagBody(Element $element, string $lowerTag, bool $isVoid, bool $flushInside): string
    {
        $tag = $element->type;
        [$props, $submitForm] = $this->bindActions($lowerTag, $element->props);
        $attributes = $this->renderAttributes($lowerTag, $props);

        if ($isVoid) {
            return "<{$tag}{$attributes} />";
        }

        $isForm = $lowerTag === 'form';
        if ($isForm) {
            $this->openFormDepth++;
        }
        try {
            $children = $this->renderContent($lowerTag, $element->children);
        } finally {
            if ($isForm) {
                $this->openFormDepth--;
            }
        }

        if ($flushInside) {
            $children .= $this->flushForms();
        }

        // 子の描画でセッションが始まることがあるので、フィールドは子の後で作る (RT-4.6)
        $fields = $submitForm === null ? '' : $this->renderFormFields($submitForm);
        $html = "<{$tag}{$attributes}>{$fields}{$children}</{$tag}>";

        return $isForm && $this->openFormDepth === 0 ? $html . $this->flushForms() : $html;
    }

    private function flushForms(): string
    {
        $html = '';
        foreach ($this->pendingForms as $form) {
            $html .= sprintf(
                '<form method="post" data-rosanna-form id="%s" hidden>%s</form>',
                $form->id,
                $this->renderFormFields($form),
            );
        }
        $this->pendingForms = [];

        return $html;
    }

    /**
     * `wire:*` の prop を、JavaScript なしでも動く HTML にする (RT-4.1)。
     *
     * @param array<string, mixed> $props
     * @return array{array<string, mixed>, ?ActionForm} 出力する props と、onSubmit なら要素自体のフォーム
     */
    private function bindActions(string $lowerTag, array $props): array
    {
        $submitForm = null;

        foreach ($props as $key => $action) {
            if (!str_starts_with((string) $key, 'wire:')) {
                continue;
            }
            unset($props[$key]);

            $event = substr((string) $key, 5);
            if (!$action instanceof Action) {
                throw new \RuntimeException(sprintf('%s must be an Action; got %s.', $key, get_debug_type($action)));
            }

            if ($action->type === Action::SET_FIELDS) {
                if ($event !== 'submit') {
                    self::assertFieldTrigger($event, $lowerTag, $props, $action);
                }
                $props['data-rosanna-with-value'] = true;
            }

            if ($event === 'submit') {
                self::assertSubmitTarget($lowerTag, $props);
                $submitForm = $this->individualForm($action);
                $props = ['method' => 'post', 'data-rosanna-form' => true] + self::withoutProp($props, 'method');
            } elseif ($event === 'click' && $lowerTag === 'button') {
                // パーサーは同じ属性の最初を読むので、大文字の利用者の値を残さない
                $props = self::withoutProp(self::withoutProp($props, 'type'), 'form');
                $props['type'] = 'submit';
                if (self::prop($props, 'name') !== null || self::prop($props, 'value') !== null) {
                    // 利用者の値を上書きしない
                    $props['form'] = $this->queueIndividualForm($action);
                } else {
                    [$props['form'], $props['value']] = $this->bindSharedForm($action);
                    $props['name'] = '_rosanna_action';
                }
            } elseif ($event === 'click' && $lowerTag === 'input' && in_array(self::lowerString(self::prop($props, 'type')), ['submit', 'image'], true)) {
                // submit は value がラベルになり、image は value が送られない
                $props = self::withoutProp($props, 'form');
                $props['form'] = $this->queueIndividualForm($action);
            } elseif (in_array($event, ['click', 'change', 'input'], true)) {
                [$props['data-rosanna-on-' . $event], $props['data-rosanna-action-' . $event]] = $this->bindSharedForm($action);
            } else {
                throw new \RuntimeException(sprintf('Unsupported event binding %s.', $key));
            }
        }

        return [$props, $submitForm];
    }

    /**
     * submit 以外で送られるのは、イベントを起こした欄の値だけ。他の欄は届かずにフックを空にするので拒否する (RT-4.3)。
     *
     * @param array<string, mixed> $props
     */
    private static function assertFieldTrigger(string $event, string $lowerTag, array $props, Action $action): void
    {
        $fields = $action->payload['fields'] ?? null;
        $problem = match (true) {
            $event === 'click' => 'onClick sends no field values; use onSubmit on the form, or onChange/onInput on the control',
            !in_array($lowerTag, ['input', 'select', 'textarea'], true) => sprintf('<%s> has no value to send', $lowerTag),
            !empty(self::prop($props, 'multiple')) || self::lowerString(self::prop($props, 'type')) === 'file' => 'a multiple or file control has no single string value',
            !is_array($fields) || array_keys($fields) !== [self::prop($props, 'name')] => 'the control can only send its own field, named by its name attribute',
            default => null,
        };

        if ($problem !== null) {
            throw new \RuntimeException('useFormAction() cannot be used here: ' . $problem . '.');
        }
    }

    /**
     * JavaScript の有無で送信先が変わるのを防ぐ (RT-4.2)。
     *
     * @param array<string, mixed> $props
     */
    private static function assertSubmitTarget(string $lowerTag, array $props): void
    {
        if ($lowerTag !== 'form') {
            throw new \RuntimeException(sprintf('onSubmit only works on <form>; got <%s>.', $lowerTag));
        }
        if (self::prop($props, 'action') !== null || self::lowerString(self::prop($props, 'method') ?? 'post') !== 'post') {
            throw new \RuntimeException('onSubmit posts the action to the current page; remove action and method from the <form>.');
        }
    }

    /**
     * 属性名は大文字小文字を区別しない。パーサーと同じく最初の値を読む (RT-4.1〜4.3)。
     *
     * @param array<string, mixed> $props
     */
    private static function prop(array $props, string $lowerName): mixed
    {
        foreach ($props as $key => $value) {
            if ($value !== null && strtolower((string) $key) === $lowerName) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function withoutProp(array $props, string $lowerName): array
    {
        foreach (array_keys($props) as $key) {
            if (strtolower((string) $key) === $lowerName) {
                unset($props[$key]);
            }
        }

        return $props;
    }

    private static function lowerString(mixed $value): ?string
    {
        return is_string($value) ? strtolower($value) : null;
    }

    /**
     * 共有フォームの ID とエンベロープを返す。action はボタンの value か `data-rosanna-action-*` が持つ (RT-4.7)。
     *
     * @return array{string, string}
     */
    private function bindSharedForm(Action $action): array
    {
        [$targetId, $state, $envelope] = $this->stamp($action);
        $baseIndex = self::baseIndexToCarry($action, $state);

        $form = $this->sharedForms[$targetId] ?? null;
        if ($form === null) {
            $form = $this->newForm($targetId, $action->storageType);
            $this->pendingForms[] = $form;
            $this->sharedForms[$targetId] = $form;
        }

        if ($baseIndex !== null && !isset($form->baseIndexes[$baseIndex])) {
            if ($form->written) {
                // RT-4.8 書き出し済みのフォームには足せない。交互に足りなくならないよう、前の基準も持たせる
                $next = $this->newForm($targetId, $action->storageType);
                $next->baseIndexes = $form->baseIndexes;
                $form = $next;
                $this->pendingForms[] = $form;
                $this->sharedForms[$targetId] = $form;
            }
            $form->baseIndexes[$baseIndex] = true;
        }

        return [$form->id, $envelope];
    }

    private function queueIndividualForm(Action $action): string
    {
        $form = $this->individualForm($action);
        $this->pendingForms[] = $form;

        return $form->id;
    }

    /** RT-4.9 */
    private function individualForm(Action $action): ActionForm
    {
        [$targetId, $state, $envelope] = $this->stamp($action);

        $form = new ActionForm(self::newFormId(), $targetId, $action->storageType, $envelope);
        $baseIndex = self::baseIndexToCarry($action, $state);
        if ($baseIndex !== null) {
            $form->baseIndexes[$baseIndex] = true;
        }

        return $form;
    }

    private function newForm(string $targetId, ?StorageType $storageType): ActionForm
    {
        return new ActionForm(self::newFormId(), $targetId, $storageType);
    }

    /**
     * ページ内で一意にするため乱数から作る。インスタンスから決めると、アプリの form の外に残った古いフォームを
     * 部分更新の後の新しいボタンが指してしまう (RT-4.4)。
     */
    private static function newFormId(): string
    {
        return 'rosanna-f-' . bin2hex(random_bytes(8));
    }

    /**
     * 描画時のバージョンを入れて署名する (VR-1、AC-20)。
     *
     * @return array{string, ComponentState, string} 対象のインスタンス ID、その state、エンベロープ
     */
    private function stamp(Action $action): array
    {
        $targetId = $action->componentId ?? $this->rootId;
        $state = self::stateOf($targetId, $action->storageType);
        $json = $action->withVersion($state->version())->toJson();

        return [$targetId, $state, new ActionEnvelope($json, $this->signer->sign($targetId, $json))->toJson()];
    }

    /** getInstance() は現在の state を変えるので戻す。描画の途中で hooks が別のインスタンスに向かないようにする */
    private static function stateOf(string $componentId, ?StorageType $storageType): ComponentState
    {
        $current = ComponentState::current();
        $state = ComponentState::getInstance($componentId, $storageType);
        ComponentState::setCurrent($current);

        return $state;
    }

    /** AC-18 差分の基準がサーバーにない Session と Memory のときだけ */
    private static function baseIndexToCarry(Action $action, ComponentState $state): ?int
    {
        $index = $action->payload['index'] ?? null;
        if (!is_int($index) || !array_key_exists('patch', $action->payload)) {
            return null;
        }
        if ($state->isSnapshotStorage() || !$state->hasRenderedValue($index)) {
            return null;
        }
        // Memory の保存値はこのリクエストで消えるので、次の POST の基準にならない
        if ($state->getStorageType() === StorageType::Session && $state->hasState($index)) {
            return null;
        }

        return $index;
    }

    /** action を除く hidden フィールド。CSRF トークンはここで読む (RT-4.6) */
    private function renderFormFields(ActionForm $form): string
    {
        $form->written = true;

        $state = self::stateOf($form->componentId, $form->storageType);
        $fields = self::hiddenField('_rosanna_component', $form->componentId);
        if ($form->envelope !== null) {
            $fields .= self::hiddenField('_rosanna_action', $form->envelope);
        }

        $token = $this->csrf->token();
        if ($token !== '') {
            $fields .= self::hiddenField(CsrfGuard::FIELD, $token);
        }
        if ($state->isSnapshotStorage()) {
            $fields .= self::hiddenField('_rosanna_snapshot', ComponentWrapper::serialize($state, $this->snapshotSerializer));
        }
        foreach (array_keys($form->baseIndexes) as $index) {
            $value = json_encode($state->renderedValue($index), Action::JSON_FLAGS);
            $fields .= self::hiddenField("_rosanna_base[{$index}]", $value);
        }
        if ($this->deferQuery !== null) {
            $fields .= self::hiddenField('_rosanna_defer_query', $this->deferQuery);
        }

        return $fields;
    }

    private static function hiddenField(string $name, string $value, string $extra = ''): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s"%s />', self::escape($name), self::escape($value), $extra);
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
                'Inline event handler attribute %s is not allowed. Use the onClick, onChange, onInput or onSubmit props with an Action.',
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

    /** defer のプレースホルダー。属性名は rosanna.js との契約 */
    private function renderDeferred(Element $element): string
    {
        $name = $element->props['__name'] ?? null;
        if (!is_string($name) || !Defer::isValidName($name)) {
            throw new \RuntimeException(sprintf('Deferred component name must match %s, got %s.', Defer::NAME_PATTERN, self::quote($name)));
        }

        $params = $element->props['__params'] ?? [];
        $query = [];
        foreach (is_array($params) ? $params : [] as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \RuntimeException("Deferred component '{$name}' params must use non-empty string keys.");
            }
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new \RuntimeException("Deferred component '{$name}' param '{$key}' must be scalar; got " . get_debug_type($value));
            }
            $query[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        $url = rtrim($this->deferPrefix, '/') . '/' . rawurlencode($name);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $attributes = sprintf('data-rosanna-defer-url="%s"', self::escape($url));
        if (($element->props['__localCache'] ?? false) === true) {
            $attributes .= ' data-rosanna-defer-cache';
            $ttl = $element->props['__localCacheTtl'] ?? 0;
            if (is_int($ttl) && $ttl > 0) {
                $attributes .= sprintf(' data-rosanna-defer-cache-ttl="%d"', $ttl);
            }
        }
        if (($element->props['__reloadable'] ?? false) === true) {
            $attributes .= sprintf(' data-rosanna-defer-name="%s"', self::escape($name));
        }

        $fallback = $element->props['__fallback'] ?? null;

        return sprintf('<div %s>%s</div>', $attributes, $fallback instanceof Element ? $this->renderNode($fallback) : '');
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
