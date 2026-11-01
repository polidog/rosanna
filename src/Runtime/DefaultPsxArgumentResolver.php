<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * default 値と nullable だけを解決する (RT-11.4)。
 *
 * default はその位置より後ろに渡すものがなければ引数を省き、PHP に既定値を適用させる。manifest の式の文字列を
 * eval しないので、`self::X` のような式も正しく動く。
 */
final class DefaultPsxArgumentResolver implements PsxArgumentResolver
{
    /** @throws \LogicException service のように解決できない引数があるとき */
    public function resolve(string $fqcn, array $metadata, array $props): array
    {
        $arguments = [];
        $pendingDefault = null;
        foreach (array_slice($metadata, 1) as $parameter) {
            $kind = is_array($parameter) ? ($parameter['kind'] ?? null) : null;
            $name = is_array($parameter) && is_scalar($parameter['name'] ?? null) ? (string) $parameter['name'] : '?';

            if ($kind === 'default') {
                $pendingDefault ??= $name;
            } elseif ($kind === 'null' && $pendingDefault === null) {
                $arguments[] = null;
            } else {
                throw new \LogicException(sprintf(
                    'PSX component %s: parameter $%s cannot be resolved without a PsxArgumentResolver. Call setArgumentResolver().',
                    $fqcn,
                    $kind === 'null' ? $pendingDefault : $name,
                ));
            }
        }

        return $arguments;
    }
}
