<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Runtime;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

use Polidog\Rosanna\Runtime\AttributeNames;

/**
 * RT-3.8。`/D` がないと `$` が末尾の改行の前にも合う
 *
 * @internal 引数は AttributeNames::MAP で変換した後の名前
 * @throws \RuntimeException
 */
function assertValidAttributeName(string $name): void
{
    $pattern = '/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/D';
    if (preg_match($pattern, $name) !== 1) {
        throw new \RuntimeException(sprintf('Invalid HTML attribute name %s: attribute names must match %s.', quote($name), $pattern));
    }
    if (preg_match('/^on[a-z]/i', $name) === 1) {
        throw new \RuntimeException(sprintf(
            'Inline event handler attribute %s is not allowed. Event props (onClick, onChange, onInput, onSubmit) need polidog/rosanna-state.',
            quote($name),
        ));
    }
}

/** @internal 引数は小文字にした属性名 */
function isUrlAttribute(string $lowerName): bool
{
    return match ($lowerName) {
        'href', 'src', 'action', 'formaction', 'data', 'poster', 'background', 'xlink:href' => true,
        default => false,
    };
}

/** @internal 引数は小文字にした属性名。true / false を値として書く属性 */
function isBooleanStringAttribute(string $lowerName): bool
{
    return str_starts_with($lowerName, 'aria-') || match ($lowerName) {
        'contenteditable', 'draggable', 'spellcheck' => true,
        default => false,
    };
}

/** @internal 引数は小文字にした属性名。アニメーション要素が対象の属性に入れる値 */
function isAnimationValueAttribute(string $lowerName): bool
{
    return match ($lowerName) {
        'to', 'values', 'from', 'by' => true,
        default => false,
    };
}

/**
 * Chromium は <set attributeName="href" to="javascript:..."> を実行する (tests/browser/svg.spec.ts)。
 * attributeName と属性名の大文字小文字はパーサーが揃えるので区別せず、接頭辞は宣言で決まるので見ない。
 *
 * @internal
 * @param array<array-key, mixed> $props
 */
function targetsHref(array $props): bool
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

/**
 * 大文字小文字を区別しない属性名で、パーサーと同じく最初の値を取る。null は属性にならないので飛ばす。
 *
 * @internal
 * @param array<array-key, mixed> $props
 */
function firstProp(array $props, string $name): mixed
{
    foreach ($props as $key => $value) {
        if ($value !== null && strcasecmp((string) $key, $name) === 0) {
            return $value;
        }
    }

    return null;
}
