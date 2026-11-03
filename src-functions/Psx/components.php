<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 *
 * コンパイル済みのファイルの AST から、manifest に書くコンポーネントを探す (CP-132)。php-parser のクラスは
 * 関数を呼んだときに初めて読み込まれるので、このファイルを読み込んでも php-parser は読み込まれない (CP-2)。
 */

use PhpParser\Node;
use Polidog\Rosanna\Psx\NamespaceContext;

/**
 * ファイルが返すコンポーネントのクロージャ。`fc()` と `deferred()` に包まれていれば中を見る。
 *
 * @internal
 * @param array<Node\Stmt> $statements
 * @throws \RuntimeException `fc()` や `deferred()` の引数を静的に読めないとき
 */
function returnedComponent(array $statements, NamespaceContext $context, string $fqcn): Node\Expr\Closure|Node\Expr\ArrowFunction|null
{
    $expr = topLevelReturn($statements)?->expr;

    if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && wrapsComponent($expr->name, $context)) {
        $component = componentArgument($expr);
        if (!isClosure($component)) {
            throw new \RuntimeException(\sprintf(
                '%s: the component passed to %s() must be a closure or arrow function written directly in the call, '
                . 'so that its parameters can be recorded in the manifest. A variable or other expression cannot be read at compile time.',
                $fqcn,
                $expr->name->toString(),
            ));
        }
        $expr = $component;
    }

    return isClosure($expr) ? $expr : null;
}

/**
 * 名前空間と use で解決した関数名が `fc` か `deferred` か。名前空間は問わない
 *
 * @internal
 */
function wrapsComponent(Node\Name $name, NamespaceContext $context): bool
{
    $function = new Node\Name($context->resolveFunctionName($name));

    return \in_array(\strtolower($function->getLast()), ['fc', 'deferred'], true);
}

/**
 * @internal
 * @psalm-assert-if-true Node\Expr\Closure|Node\Expr\ArrowFunction $expr
 * @phpstan-assert-if-true Node\Expr\Closure|Node\Expr\ArrowFunction $expr
 */
function isClosure(?Node\Expr $expr): bool
{
    return $expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction;
}

/**
 * `fc(key: 'k', component: fn...)` のように、名前付きなら位置は決まらない。
 *
 * @internal
 */
function componentArgument(Node\Expr\FuncCall $call): ?Node\Expr
{
    foreach ($call->args as $argument) {
        if ($argument instanceof Node\Arg && $argument->name?->toString() === 'component') {
            return $argument->value;
        }
    }

    $first = $call->args[0] ?? null;

    return $first instanceof Node\Arg && $first->name === null ? $first->value : null;
}

/**
 * ファイルの最上位 (namespace の中を含む) の最初の return
 *
 * @internal
 * @param array<Node\Stmt> $statements
 */
function topLevelReturn(array $statements): ?Node\Stmt\Return_
{
    foreach ($statements as $statement) {
        if ($statement instanceof Node\Stmt\Return_) {
            return $statement;
        }
        if ($statement instanceof Node\Stmt\Namespace_) {
            $nested = topLevelReturn($statement->stmts);
            if ($nested !== null) {
                return $nested;
            }
        }
    }

    return null;
}
