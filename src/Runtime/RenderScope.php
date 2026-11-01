<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;

/**
 * 描画中の ambient。`RenderContext::run()` の間だけ有効で、リクエストごとに作る。
 */
final class RenderScope
{
    /**
     * @param ActionSigner $signer 鍵がなければ sign() が null を返す
     * @param string $signingScope `ActionSigner::signingScope()` の値。page:{パス} か defer:{名前}
     * @param string|null $deferredEndpointName defer エンドポイントの描画なら登録名
     * @param string|null $deferredEndpointComponent その登録の component (PSX の FQCN)
     */
    public function __construct(
        public readonly RendererFactory $renderers,
        public readonly PsxRenderer $psx,
        public readonly ?SnapshotSerializer $snapshotSerializer,
        public readonly ActionSigner $signer,
        public readonly string $signingScope,
        private readonly ?string $deferredEndpointName = null,
        private readonly ?string $deferredEndpointComponent = null,
    ) {}

    /**
     * 対象自身だけがプレースホルダーを飛ばす。入れ子の defer はプレースホルダーのままにする (RT-6.2、RT-9.4)。
     * 親のキャッシュの方針で子の出力が返るのを防ぐ。
     */
    public function isDeferredEndpointTarget(FunctionComponent $component): bool
    {
        if ($this->deferredEndpointName === null) {
            return false;
        }
        if ($component->defer?->name === $this->deferredEndpointName) {
            return true;
        }

        return $this->deferredEndpointComponent !== null
            && $this->psx->loaded($this->deferredEndpointComponent) === $component;
    }
}
