<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 *
 * nikic/php-parser の型ノードを読む。名前は型の宣言にしか書かないので、このファイルを読み込んでも
 * php-parser のクラスは読み込まれない (CP-2)。
 */

use PhpParser\Node;

/**
 * CP-133 の service。`Foo`、`?Foo`、`Foo|null` のように、null を除くとクラス名が 1 つだけ残る型ならその名前を返す
 *
 * @internal
 */
function serviceType(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?Node\Name
{
    if ($type instanceof Node\Name) {
        return $type;
    }
    if ($type instanceof Node\NullableType) {
        return $type->type instanceof Node\Name ? $type->type : null;
    }
    if ($type instanceof Node\UnionType && allowsNullType($type)) {
        $others = \array_values(\array_filter($type->types, static fn(Node $t): bool => !isNullType($t)));

        return \count($others) === 1 && $others[0] instanceof Node\Name ? $others[0] : null;
    }

    return null;
}

/**
 * CP-133 の null。`?T`、null を含む union、`null` そのものなら true。型がなければ false
 *
 * @internal
 */
function allowsNullType(Node\Identifier|Node\Name|Node\ComplexType|null $type): bool
{
    if ($type instanceof Node\NullableType) {
        return true;
    }
    if ($type instanceof Node\UnionType) {
        foreach ($type->types as $member) {
            if (isNullType($member)) {
                return true;
            }
        }
    }

    return isNullType($type);
}

/**
 * php-parser は組み込みの型を Identifier にする。大文字小文字は区別しない
 *
 * @internal
 */
function isNullType(?Node $type): bool
{
    return $type instanceof Node\Identifier && $type->toLowerString() === 'null';
}
