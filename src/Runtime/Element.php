<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * 描画前の木を表す値。type の `Fragment`、`__defer__`、`__function_component__` は予約値で、タグとして出力しない (RT-1.2)。
 */
final readonly class Element
{
    /**
     * @param array<string, mixed> $props
     * @param array<mixed> $children
     */
    public function __construct(
        public string $type,
        public array $props = [],
        public array $children = [],
    ) {}

    /** @param array<string, mixed> $props */
    public function withProps(array $props): self
    {
        return new self($this->type, $props, $this->children);
    }

    /** @param array<mixed> $children */
    public function withChildren(array $children): self
    {
        return new self($this->type, $this->props, $children);
    }
}
