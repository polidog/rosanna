<?php

declare(strict_types=1);

namespace Polidog\Rosanna;

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\ComponentInterface;
use Polidog\Rosanna\Component\ComponentRegistry;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Http\Response;
use Polidog\Rosanna\Protocol\ActionHandler;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Protocol\SessionSnapshots;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\ComponentWrapper;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\Runtime\RendererFactory;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Snapshot\SnapshotUrl;
use Polidog\Rosanna\Snapshot\SnapshotVerificationException;
use Polidog\Rosanna\Storage\NativeSession;

/**
 * ランタイムの入口。登録と設定だけを持ち、リクエストごとの情報は残さない (RQ-3)。
 * URL からページを選ぶのは host の役割で、`run()` は持たない。
 */
final class Rosanna
{
    private const DEFAULT_CACHE_CONTROL = 'private, max-age=0';

    /**
     * 外部の rosanna.js が読めないときだけ defer を 1 回取得する。フォームは通常の POST に落ちる。
     * CSP の nonce で動くよう、インラインのイベントハンドラを使わない。defer の script は DOMContentLoaded の前に動くので、
     * その時点で reloadDefer がなければ読み込みに失敗している。
     */
    private const DEFER_FALLBACK_SCRIPT = <<<'JS'
        (function(){
        window.Rosanna=window.Rosanna||{};
        var B='data-rosanna-defer-fallback-loading';
        function warn(m,u){if(window.console&&console.warn)console.warn('[Rosanna] '+m,u);}
        function prefix(){var r=window.Rosanna,p=r&&typeof r.deferPrefix==='string'&&r.deferPrefix!==''?r.deferPrefix:'/_defer';return p.replace(/\/+$/,'');}
        function allowed(url){try{var u=new URL(url,location.href);return u.origin===location.origin&&u.pathname.indexOf(prefix()+'/')===0;}catch(e){return false;}}
        function scan(root){root.querySelectorAll('[data-rosanna-defer-url]:not([data-rosanna-defer-loaded]):not(['+B+'])').forEach(load);}
        function place(el,html){var t=document.createElement('template');t.innerHTML=html;scan(t.content);if(el.hasAttribute('data-rosanna-defer-name')){el.replaceChildren(t.content);el.setAttribute('data-rosanna-defer-loaded','');}else{el.replaceWith(t.content);}}
        function load(el){var url=el.getAttribute('data-rosanna-defer-url');if(!allowed(url)){warn('refusing defer fetch outside the same-origin defer prefix',url);return;}el.setAttribute(B,'');el.setAttribute('aria-busy','true');fetch(url,{method:'GET',headers:{'X-Rosanna-Defer':'1'},credentials:'same-origin'}).then(function(r){var type=(r.headers.get('content-type')||'').trim().toLowerCase();if(!r.ok||type.indexOf('text/html')!==0||(r.url&&!allowed(r.url)))return null;return r.text();}).then(function(html){if(html!==null)place(el,html);}).catch(function(e){warn('defer fallback fetch failed',e);}).finally(function(){el.removeAttribute(B);el.removeAttribute('aria-busy');});}
        function check(){setTimeout(function(){if(!(window.Rosanna&&window.Rosanna.reloadDefer))scan(document);},0);}
        if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',check,{once:true});else check();
        })();
        JS;

    private ComponentRegistry $registry;

    private PsxRenderer $psx;

    private ?string $snapshotSecret = null;

    private ?SnapshotSerializer $snapshotSerializer = null;

    private bool $csrfEnabled = true;

    private bool $trustProxyHeaders = false;

    private string $deferPrefix = Renderer::DEFAULT_DEFER_PREFIX;

    /** @var array<string, array{component: string, cacheControl: ?string}> */
    private array $deferred = [];

    /**
     * replay の間だけ、描画に渡された Element を集める。finally で必ず戻すのでリクエストをまたがない (HD-6)。
     *
     * @var list<Element>|null
     */
    private ?array $collected = null;

    public function __construct()
    {
        $this->registry = new ComponentRegistry();
        $this->psx = new PsxRenderer();
    }

    /**
     * #[Defer] があれば、エンドポイントと、PSX のページ側で使うプレースホルダーも登録する (RT-12.5)。
     *
     * @param class-string<ComponentInterface> $className
     * @throws \InvalidArgumentException
     */
    public function register(string $className): self
    {
        $this->registry->register($className);

        $defer = $this->registry->getDefer($className::getComponentName());
        if ($defer !== null) {
            $this->psx->register($className, static fn(array $props): Element => $defer->buildPlaceholder($props));
            $this->registerDeferredIfNew($defer->name, $className, $defer->cacheControl);
        }

        return $this;
    }

    public function registerComponent(string $fqcn, callable $component): self
    {
        $this->psx->register($fqcn, $component);

        return $this;
    }

    /**
     * RT-11.5、DF-13
     *
     * @throws \RuntimeException ファイルがない、配列を返さない、項目の形が違うとき
     */
    public function loadComponentManifest(string $path): self
    {
        foreach ($this->psx->loadManifest($path) as $name => $entry) {
            $this->registerDeferredIfNew((string) $name, $entry['component'], $entry['cacheControl']);
        }

        return $this;
    }

    public function setArgumentResolver(PsxArgumentResolver $resolver): self
    {
        $this->psx->setArgumentResolver($resolver);

        return $this;
    }

    /** @throws \InvalidArgumentException 鍵が空のとき (SN-4) */
    public function setSnapshotSecret(#[\SensitiveParameter] string $secretKey): self
    {
        $this->snapshotSerializer = new SnapshotSerializer($secretKey);
        $this->snapshotSecret = $secretKey;

        return $this;
    }

    /** 秘密鍵がなければ null。例外を投げない (SN-9) */
    public function snapshotSerializer(): ?SnapshotSerializer
    {
        return $this->snapshotSerializer;
    }

    /** setSnapshotSecret や registerDeferred が後から呼ばれるのでキャッシュしない */
    public function signer(): ActionSigner
    {
        return new ActionSigner($this->snapshotSecret, array_map('strval', array_keys($this->deferred)));
    }

    public function csrf(): CsrfGuard
    {
        return new CsrfGuard($this->csrfEnabled, $this->trustProxyHeaders);
    }

    public function disableCsrfProtection(): self
    {
        $this->csrfEnabled = false;

        return $this;
    }

    public function trustProxyHeaders(bool $trust = true): self
    {
        $this->trustProxyHeaders = $trust;

        return $this;
    }

    public function actions(): ActionHandler
    {
        return new ActionHandler($this);
    }

    /**
     * 同じ名前の登録は例外 (DF-3)。名前は URL になるので、別の内容を黙って上書きしない。
     *
     * @throws \InvalidArgumentException 名前が不正か、登録済みのとき
     */
    public function registerDeferred(string $name, string $component, ?string $cacheControl = null): self
    {
        Defer::assertValidName($name);
        if (isset($this->deferred[$name])) {
            throw new \InvalidArgumentException(sprintf(
                "Deferred name '%s' is already registered (component: %s). Deferred names are part of the public URL surface and must be unique.",
                $name,
                $this->deferred[$name]['component'],
            ));
        }
        $this->deferred[$name] = ['component' => $component, 'cacheControl' => $cacheControl];

        return $this;
    }

    /** @throws \InvalidArgumentException `/` で始まらないか、ルートそのものを指すとき */
    public function setDeferPrefix(string $prefix): self
    {
        $normalized = rtrim($prefix, '/');
        if ($normalized === '' || !str_starts_with($normalized, '/') || str_starts_with($normalized, '//')) {
            throw new \InvalidArgumentException("Defer prefix must be a path like '/_defer', got: '{$prefix}'");
        }
        $this->deferPrefix = $normalized;

        return $this;
    }

    /**
     * RT-12.8。$deferName があれば defer エンドポイントの描画とし、署名の scope を defer:{名前} にする。
     */
    public function scope(Request $request, ?string $deferName = null): RenderScope
    {
        $signer = $this->signer()->forRequest($request, $deferName);

        return $this->newScope(
            $signer,
            $signer->signingScope() ?? throw new \LogicException('A signer bound to a request always has a signing scope.'),
            $deferName,
            $deferName === null ? null : ($this->deferred[$deferName]['component'] ?? null),
        );
    }

    /**
     * ラッパーの div 付きの HTML。新しいパスを始め、終わったら戻す (RT-12.3)。
     *
     * @throws \LogicException 登録されていないとき (RT-12.2)、秘密鍵なしの Snapshot (RT-12.4)
     */
    public function render(string $componentName, ?string $key = null): string
    {
        $name = $this->requireComponentName($componentName);

        return $this->inScope(function (RenderScope $scope) use ($name, $key): string {
            $outerPass = RenderContext::currentPass();
            try {
                RenderContext::beginRender();
                $instanceId = RenderContext::nextInstanceId($name, $key);
                $component = $this->newComponent($name);
                $renderer = $scope->renderers->create($instanceId, $this->registry->getStorageType($name), $this->registry->getPersist($name));

                return $renderer->render(function () use ($component, $instanceId): Element {
                    $state = ComponentState::current();
                    if ($component instanceof BaseComponent && $state !== null) {
                        $component->setComponentState($state);
                    }
                    $inner = $component->render();
                    if ($this->collected !== null) {
                        $this->collected[] = new Element('div', ['data-rosanna' => $instanceId], [$inner]);
                    }

                    return $inner;
                });
            } finally {
                RenderContext::resumePass($outerPass);
            }
        });
    }

    /**
     * ラッパー付きの Element。現在のパスを続ける (RT-12.3)。
     *
     * @throws \LogicException 登録されていないとき (RT-12.2)、秘密鍵なしの Snapshot (RT-12.4)
     */
    public function createElement(string $componentName, ?string $key = null): Element
    {
        $name = $this->requireComponentName($componentName);

        return $this->inScope(function () use ($name, $key): Element {
            $component = $this->newComponent($name);
            $state = ComponentState::getInstance(RenderContext::nextInstanceId($name, $key), $this->registry->getStorageType($name));
            ComponentState::reset();
            if ($component instanceof BaseComponent) {
                $component->setComponentState($state);
            }
            $inner = $component->render();

            // スナップショットには描画で使われたフックの値が要るので、render() の後に作る
            return new Element('div', ComponentWrapper::propsInScope($state, $this->registry->getPersist($name)), [$inner]);
        });
    }

    /** ルート ID は `_root_`。現在のパスを続ける (RT-12.3) */
    public function renderElement(Element $element): string
    {
        if ($this->collected !== null) {
            $this->collected[] = $element;
        }

        return $this->inScope(static fn(RenderScope $scope): string => $scope->renderers->create('_root_')->renderElement($element));
    }

    /**
     * GET の描画の前に呼ぶ。Isolated では何もしない (SN-28)。失敗はログに出して状態なしで描画させる (SN-24、SN-27)。
     */
    public function restoreSnapshots(Request $request, SnapshotBehavior $behavior = SnapshotBehavior::Isolated): void
    {
        if ($behavior === SnapshotBehavior::Isolated) {
            return;
        }

        $carried = SnapshotUrl::extract($request);
        $parent = ComponentState::current();
        try {
            if ($behavior === SnapshotBehavior::Persistent) {
                if ($carried !== null) {
                    $this->restorePersistentSnapshot($carried);
                }

                return;
            }

            // SN-23 別のセッションで得た署名済みのスナップショットをリンクで植え付けられるのを防ぐ
            if ($carried !== null) {
                error_log('[Rosanna] Snapshot ignored (behavior=Session): request-carried snapshots are not accepted');
            }
            NativeSession::resumeFromCookie($request->cookies);
            if ($this->snapshotSerializer === null || !NativeSession::isActive()) {
                return;
            }
            foreach (SessionSnapshots::load($this->snapshotSerializer) as $snapshot) {
                ComponentState::fromSnapshot($snapshot);
            }
        } finally {
            ComponentState::setCurrent($parent);
        }
    }

    /**
     * DF-1〜DF-12。host が `{prefix}/{name}` へのリクエストをページの処理より前に渡す。
     */
    public function handleDeferred(Request $request): ?Response
    {
        if ($request->method !== 'GET' || ($request->path !== $this->deferPrefix && !str_starts_with($request->path, $this->deferPrefix . '/'))) {
            return null;
        }

        $name = rawurldecode(substr($request->path, strlen($this->deferPrefix) + 1));
        if (!Defer::isValidName($name)) {
            return self::plainResponse(404, 'Not Found');
        }

        $registration = $this->deferred[$name] ?? null;
        if ($registration === null) {
            return self::plainResponse(404, 'Deferred component not registered: ' . self::escape($name));
        }

        // DF-4 key がインスタンスの ID を決めるので、URL から他人の状態を選ばせない
        $props = [];
        foreach ($request->query as $param => $value) {
            $param = (string) $param;
            if ($param === 'key' || $param === 'fallback') {
                continue;
            }
            if (!is_scalar($value)) {
                return self::plainResponse(400, 'Deferred component param "' . self::escape($param) . '" must be a scalar.');
            }
            $props[$param] = $value;
        }

        try {
            $body = $this->renderDeferredEndpoint($request, $name, $props);
        } catch (\Throwable $e) {
            // DF-7 例外のメッセージには FQCN やパスが入るので、公開のエンドポイントで見せない
            error_log(sprintf("[Rosanna] defer render failed for name '%s' (component %s): %s\n%s", $name, $registration['component'], $e->getMessage(), $e->getTraceAsString()));

            return self::plainResponse(500, 'Failed to render deferred component.');
        }

        return new Response(200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => self::cacheControlFor($name, $registration['cacheControl'] ?? self::DEFAULT_CACHE_CONTROL),
        ], $body);
    }

    /**
     * RT-12.6。$src はエスケープし、$deferPrefix は JS の文字列として `</` で script を閉じられないように出す。
     * setDeferPrefix() を使うアプリは同じ値を渡す。
     */
    public static function renderClientScript(string $src = '/rosanna.js', ?string $deferPrefix = null, ?string $nonce = null): string
    {
        $nonceAttribute = $nonce === null ? '' : ' nonce="' . self::escape($nonce) . '"';

        $prefixScript = '';
        if ($deferPrefix !== null) {
            $json = json_encode($deferPrefix, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $prefixScript = "<script{$nonceAttribute}>window.Rosanna=window.Rosanna||{};window.Rosanna.deferPrefix={$json};</script>\n";
        }

        return $prefixScript
            . "<script{$nonceAttribute}>" . self::DEFER_FALLBACK_SCRIPT . "</script>\n"
            . '<script src="' . self::escape($src) . '" defer' . $nonceAttribute . '></script>';
    }

    /** @internal ActionHandler のため */
    public function components(): ComponentRegistry
    {
        return $this->registry;
    }

    /**
     * $fn の間に描画に渡された Element を集める (HD-6)。
     *
     * @internal ActionHandler のため
     * @template T
     * @param callable(): T $fn
     * @return array{T, list<Element>}
     */
    public function collectRendered(callable $fn): array
    {
        $outer = $this->collected;
        $this->collected = [];
        try {
            $result = $fn();

            return [$result, $this->collected];
        } finally {
            $this->collected = $outer;
        }
    }

    /**
     * replay の木で見つけたプレースホルダーの対象を、エンドポイントと同じ形で描画する (HD-9)。
     * 失敗は探しているインスタンスの有無を何も言わないので、ログに出して飛ばす。
     *
     * @internal ActionHandler のため
     * @param array<string, string> $props
     */
    public function renderDeferredForReplay(Request $request, string $name, array $props): ?Element
    {
        if (!isset($this->deferred[$name])) {
            return null;
        }

        $outerGet = $_GET;
        $_GET = $props;
        $level = ob_get_level();
        ob_start();
        try {
            return RenderContext::run($this->scope($request, $name), function () use ($name, $props): Element {
                RenderContext::beginRender();

                return $this->deferredTarget($name, $props);
            });
        } catch (\Throwable $e) {
            error_log(sprintf("[Rosanna] defer render failed for name '%s' during a replay: %s", $name, $e->getMessage()));

            return null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $_GET = $outerGet;
        }
    }

    /**
     * 外側のスコープの署名の scope を使う (RT-12.9)。同じアプリのスコープならそのまま使い、エンドポイントの判定も引き継ぐ。
     * 別のアプリのスコープの中では、鍵と serializer は自分のものを使う。
     *
     * @template T
     * @param callable(RenderScope): T $fn
     * @return T
     */
    private function inScope(callable $fn): mixed
    {
        $outer = RenderContext::currentScope();
        if ($outer !== null && $outer->psx === $this->psx) {
            $scope = $outer;
        } elseif ($outer !== null) {
            // 束縛しない signer は描画中の RenderScope::$signingScope を使う
            $scope = $this->newScope($this->signer(), $outer->signingScope, null, null);
        } else {
            $scope = $this->scope(Request::fromGlobals());
        }

        $parent = ComponentState::current();
        try {
            return RenderContext::run($scope, static fn(): mixed => $fn($scope));
        } finally {
            ComponentState::setCurrent($parent);
        }
    }

    private function newScope(ActionSigner $signer, string $signingScope, ?string $deferName, ?string $deferComponent): RenderScope
    {
        return new RenderScope(
            new RendererFactory($this->snapshotSerializer, $signer, $this->csrf(), $this->deferPrefix),
            $this->psx,
            $this->snapshotSerializer,
            $signer,
            $signingScope,
            $deferName,
            $deferComponent,
        );
    }

    /** RT-12.1 登録名とクラス名のどちらでも同じインスタンスになる */
    private function requireComponentName(string $componentName): string
    {
        if ($this->registry->has($componentName)) {
            return $componentName;
        }

        return $this->registeredNameOf($componentName) ?? throw new \LogicException(sprintf(
            'Component %s is not registered. Call register() with its class before rendering it.',
            $componentName,
        ));
    }

    private function registeredNameOf(string $className): ?string
    {
        $name = array_search($className, $this->registry->all(), true);

        return $name === false ? null : (string) $name;
    }

    private function newComponent(string $name): ComponentInterface
    {
        return $this->registry->create($name) ?? throw new \LogicException("Component {$name} is not registered.");
    }

    private function registerDeferredIfNew(string $name, string $component, ?string $cacheControl): void
    {
        $existing = $this->deferred[$name] ?? null;
        if ($existing === null || $existing['component'] !== $component || $existing['cacheControl'] !== $cacheControl) {
            $this->registerDeferred($name, $component, $cacheControl);
        }
    }

    private function restorePersistentSnapshot(string $json): void
    {
        if ($this->snapshotSerializer === null) {
            error_log('[Rosanna] Snapshot ignored (behavior=Persistent): no secret key is set');

            return;
        }

        try {
            ComponentState::fromSnapshot($this->snapshotSerializer->deserialize($json));
        } catch (SnapshotVerificationException $e) {
            error_log('[Rosanna] Snapshot rejected (behavior=Persistent): ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $props
     * @throws \Throwable 描画の例外。呼び出し側が 500 にする
     */
    private function renderDeferredEndpoint(Request $request, string $name, array $props): string
    {
        $outerGet = $_GET;
        $outerPass = RenderContext::currentPass();
        // クラスコンポーネントはパラメーターを $_GET から読む。host がパスを書き換えていてもこのリクエストの値にする
        $_GET = $request->query;
        $level = ob_get_level();
        ob_start();
        try {
            $scope = $this->scope($request, $name);
            $html = RenderContext::run($scope, function () use ($scope, $request, $name, $props): string {
                RenderContext::beginRender();
                $element = $this->deferredTarget($name, $props);

                // HD-11 クラスコンポーネントのフォームが部分更新のためにこのクエリを運ぶ
                return $scope->renderers
                    ->create('_root_', deferQuery: $request->queryString === '' ? null : $request->queryString)
                    ->renderElement($element);
            });

            return (string) ob_get_contents() . $html;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $_GET = $outerGet;
            RenderContext::resumePass($outerPass);
        }
    }

    /** @param array<string, mixed> $props */
    private function deferredTarget(string $name, array $props): Element
    {
        $component = $this->deferred[$name]['component'] ?? throw new \LogicException("Deferred component {$name} is not registered.");

        // クラスコンポーネントは props を受けず、$_GET か state から読む
        $registered = $this->registry->has($component) ? $component : $this->registeredNameOf($component);
        if ($registered !== null) {
            return $this->createElement($registered);
        }

        return RenderContext::scope()->psx->render($component, $props);
    }

    /**
     * DF-8。セッションは描画の途中で始まることがあるので描画の後に確かめる。ポリシーだけでなく送信予定のヘッダーも見るのは、
     * `session.cache_limiter = public` が `private` のポリシーを持つ応答にも public を送るため。
     * リクエストに Cookie があるだけでは該当しない。
     */
    private static function cacheControlFor(string $name, string $policy): string
    {
        $pending = headers_list();
        if (!self::allowsSharedCache($policy) && !self::pendingAllowsSharedCache($pending)) {
            return $policy;
        }

        $reasons = [];
        if (NativeSession::isActive() || (session_id() ?: '') !== '') {
            $reasons[] = 'uses the session';
        }
        foreach ($pending as $header) {
            if (stripos($header, 'set-cookie:') === 0) {
                $reasons[] = 'sets a cookie';
                break;
            }
        }
        if ($reasons === []) {
            return $policy;
        }

        $reason = implode(' and ', $reasons);
        if (headers_sent()) {
            // DF-9
            error_log(sprintf("[Rosanna] defer endpoint '%s' %s, but headers were already sent, so it may stay cacheable", $name, $reason));
        }
        error_log(sprintf("[Rosanna] defer endpoint '%s' (cacheControl \"%s\") would be stored by shared caches but %s; using \"private, no-store\"", $name, $policy, $reason));

        return 'private, no-store';
    }

    /** `private` か `no-store` を含まないものを共有キャッシュを許すと扱う。フィールド名つきの `private="x"` は含まない */
    private static function allowsSharedCache(string $cacheControl): bool
    {
        $directives = array_map(trim(...), explode(',', strtolower($cacheControl)));

        return !in_array('private', $directives, true) && !in_array('no-store', $directives, true);
    }

    /** @param list<string> $headers */
    private static function pendingAllowsSharedCache(array $headers): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, 'cache-control:') === 0 && self::allowsSharedCache(substr($header, strlen('cache-control:')))) {
                return true;
            }
        }

        return false;
    }

    private static function plainResponse(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'], $body);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
