<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * `Renderer` の差し替え口。描画ごとの可変状態を持てるよう、描画ごとに作る。
 */
interface RenderExtension
{
    /** 最も外側の描画の開始 */
    public function beginRender(): void;

    /** 最も外側の描画が成功したときに、末尾に足す HTML */
    public function finishRender(): string;

    /** 最も外側の描画の終了。例外のときも呼ぶ */
    public function endRender(): void;

    /**
     * 検証済みのタグを HTML にする。属性の検査と子の描画は渡されたクロージャで `Renderer` に任せる。
     *
     * @param string $lowerTag 小文字のタグ名
     * @param bool $isVoid 子も終了タグも持たない要素
     * @param \Closure(string, array<string, mixed>): string $attributes タグ名と props を属性の文字列にする
     * @param \Closure(string, array<mixed>): string $content タグ名と children を HTML にする
     */
    public function renderTag(Element $element, string $lowerTag, bool $isVoid, \Closure $attributes, \Closure $content): string;
}
