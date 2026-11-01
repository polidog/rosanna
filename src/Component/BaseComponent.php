<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Component;

use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\ComponentWrapper;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\HookSupport;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\ResolvesItself;
use Polidog\Rosanna\Storage\StorageType;

abstract class BaseComponent implements ComponentInterface, ResolvesItself
{
    private ?ComponentState $componentState = null;

    /** @internal */
    public function setComponentState(ComponentState $state): void
    {
        $this->componentState = $state;
    }

    public function getComponentState(): ?ComponentState
    {
        return $this->componentState;
    }

    /**
     * setter の Action に storageType を入れない。action の処理時にレジストリの設定で決める (RT-5.3)。
     *
     * @template T
     * @param T $initial
     * @return array{0: T, 1: callable(T): Action}
     */
    #[\NoDiscard]
    protected function useState(mixed $initial): array
    {
        if ($this->componentState === null) {
            $componentId = RenderContext::currentComponentId();

            return [$initial, static fn(mixed $value): Action => Action::setState(0, $value, $componentId)];
        }

        /** @var array{0: T, 1: callable(T): Action} */
        return HookSupport::useState($this->componentState, $initial, null);
    }

    /** state が設定済みのインスタンスは、新しい番号を取らず同じ状態を読む (RT-5.4) */
    final public function resolve(): Element
    {
        $attribute = Component::forClass(static::class);
        $parentState = ComponentState::current();
        $state = $this->componentState;

        if ($state === null) {
            $instanceId = RenderContext::beginComponent(static::getComponentName());
        } else {
            RenderContext::enterComponent($state->getComponentId());
        }

        try {
            if ($state === null) {
                $state = ComponentState::getInstance($instanceId, $attribute->storageType ?? StorageType::Session);
                $this->setComponentState($state);
            } else {
                ComponentState::setCurrent($state);
            }
            ComponentState::reset();
            $result = $this->render();

            // スナップショットには描画で使われたフックの値が要る
            return new Element('div', ComponentWrapper::propsInScope($state, $attribute?->persist), [$result]);
        } finally {
            RenderContext::endComponent();
            ComponentState::setCurrent($parentState);
        }
    }

    public static function getComponentName(): string
    {
        return Component::forClass(static::class)->name ?? static::class;
    }
}
