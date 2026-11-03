<?php

declare(strict_types=1);

namespace Polidog\Rosanna;

use Polidog\Rosanna\Runtime\DeferredRenderer;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\Runtime\RenderExtension;
use Polidog\Rosanna\Runtime\RenderScope;

/**
 * 描画の入口。登録と設定だけを持ち、リクエストごとの情報は残さない (RQ-3)。
 * URL からページを選ぶのは host の役割で、`run()` は持たない。
 */
final class Rosanna
{
    private PsxRenderer $psx;

    /** @var \Closure(PsxRenderer, ?RenderScope): RenderScope */
    private \Closure $scopeFactory;

    /**
     * 引数は `polidog/rosanna-state` と `polidog/rosanna-defer` のための差し替え口で、描画だけなら何も渡さない。
     *
     * @param (\Closure(RenderScope): RenderExtension)|null $extensionFactory `renderElement()` のたびに呼ぶ
     * @param (\Closure(PsxRenderer, ?RenderScope): RenderScope)|null $scopeFactory 新しいスコープを作る。第 2 引数は外のスコープ (あれば)
     * @param (\Closure(RenderScope): DeferredRenderer)|null $deferredFactory `renderElement()` のたびに呼ぶ
     */
    public function __construct(
        private readonly ?\Closure $extensionFactory = null,
        ?\Closure $scopeFactory = null,
        private readonly ?\Closure $deferredFactory = null,
    ) {
        $this->psx = new PsxRenderer();
        $this->scopeFactory = $scopeFactory ?? static fn(PsxRenderer $psx, ?RenderScope $outer): RenderScope => new RenderScope($psx, $outer?->state);
    }

    /** @param callable $component コンポーネント本体 */
    public function registerComponent(string $fqcn, callable $component): self
    {
        $this->psx->register($fqcn, $component);

        return $this;
    }

    /**
     * RT-11.5
     *
     * @throws \RuntimeException ファイルがない、配列を返さない、項目の形が違うとき
     */
    public function loadComponentManifest(string $path): self
    {
        $this->psx->loadManifest($path);

        return $this;
    }

    public function setArgumentResolver(PsxArgumentResolver $resolver): self
    {
        $this->psx->setArgumentResolver($resolver);

        return $this;
    }

    /** RT-12.8 */
    public function scope(): RenderScope
    {
        return ($this->scopeFactory)($this->psx, null);
    }

    /** RT-12.3 */
    public function renderElement(Element $element): string
    {
        return $this->inScope(function (RenderScope $scope) use ($element): string {
            $extension = $this->extensionFactory === null ? null : ($this->extensionFactory)($scope);

            $deferred = $this->deferredFactory === null ? null : ($this->deferredFactory)($scope);

            return new Renderer($extension, $deferred)->renderElement($element);
        });
    }

    /** @internal 状態層のため */
    public function psx(): PsxRenderer
    {
        return $this->psx;
    }

    /**
     * 同じアプリのスコープの中ならそのまま使い、それ以外は新しく作る (RT-12.9)。
     *
     * @internal 状態層のため
     * @template T
     * @param callable(RenderScope): T $fn
     * @return T
     */
    public function inScope(callable $fn): mixed
    {
        $outer = RenderContext::currentScope();
        $scope = $outer !== null && $outer->psx === $this->psx ? $outer : ($this->scopeFactory)($this->psx, $outer);

        return RenderContext::run($scope, static fn(): mixed => $fn($scope));
    }
}
