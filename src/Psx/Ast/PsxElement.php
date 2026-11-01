<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx\Ast;

/**
 * @internal
 */
final readonly class PsxElement
{
    /** CP-62 */
    private const array DEFER_ATTRIBUTES = ['name', 'params', 'fallback', 'localCache', 'reloadable', 'localCacheTtl'];

    /**
     * @param string $name 空文字は Fragment
     * @param list<PsxAttribute> $attributes
     * @param list<PsxElement|PsxText|PsxCode> $children
     * @param int $start `<` の位置
     */
    public function __construct(
        public string $name,
        public array $attributes,
        public array $children,
        public int $start,
    ) {}

    public function isFragment(): bool
    {
        return $this->name === '';
    }

    /** CP-11 */
    public function isComponent(): bool
    {
        return $this->name !== '' && \ctype_upper($this->name[0]);
    }

    /** CP-62 */
    public function isDeferCall(): bool
    {
        if ($this->name !== 'defer' || $this->children !== []) {
            return false;
        }
        $names = \array_map(static fn(PsxAttribute $attribute): string => $attribute->name, $this->attributes);

        return \in_array('name', $names, true) && \array_diff($names, self::DEFER_ATTRIBUTES) === [];
    }
}
