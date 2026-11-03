<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use function Polidog\Rosanna\Internal\Functions\Psx\allowsNullType;
use function Polidog\Rosanna\Internal\Functions\Psx\serviceType;

/**
 * コンパイル済みのファイルから、manifest の `parameters` を求める (CP-132、CP-133)。
 *
 * @internal
 */
final class ComponentParameters
{
    /**
     * @return list<array{kind: string, name: string, service?: string, value?: string}>
     *
     * @throws \RuntimeException パラメータを解釈できないとき
     */
    public function collect(string $compiled, string $fqcn): array
    {
        try {
            $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($compiled);
        } catch (Error) {
            return [];
        }

        $context = NamespaceContext::fromSource($compiled);
        $component = $this->returnedComponent($statements ?? [], $context, $fqcn);
        if ($component === null) {
            return [];
        }

        $printer = new Standard();
        $parameters = [];

        foreach ($component->params as $index => $parameter) {
            $name = $parameter->var instanceof Node\Expr\Variable && \is_string($parameter->var->name)
                ? $parameter->var->name
                : '';
            $type = $parameter->type;

            if ($index === 0 && ($type === null || ($type instanceof Node\Identifier && $type->toLowerString() === 'array'))) {
                $parameters[] = ['kind' => 'props', 'name' => $name];
            } elseif (($service = serviceType($type)) !== null) {
                $parameters[] = ['kind' => 'service', 'name' => $name, 'service' => $context->resolveClassName($service)];
            } elseif ($parameter->default !== null) {
                $parameters[] = ['kind' => 'default', 'name' => $name, 'value' => $printer->prettyPrintExpr($parameter->default)];
            } elseif (allowsNullType($type)) {
                $parameters[] = ['kind' => 'null', 'name' => $name];
            } else {
                throw new \RuntimeException(\sprintf(
                    '%s: cannot describe parameter $%s in the manifest. Use array or no type for the first parameter, '
                    . 'a class or interface type, a default value, or a nullable type.',
                    $fqcn,
                    $name,
                ));
            }
        }

        return $parameters;
    }

    /**
     * @param array<Node\Stmt> $statements
     *
     * @throws \RuntimeException `fc()` や `deferred()` の引数を静的に読めないとき
     */
    private function returnedComponent(array $statements, NamespaceContext $context, string $fqcn): Node\Expr\Closure|Node\Expr\ArrowFunction|null
    {
        $expr = $this->topLevelReturn($statements)?->expr;

        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && $this->wrapsComponent($expr->name, $context)) {
            $component = $this->componentArgument($expr);
            if (!$this->isClosure($component)) {
                throw new \RuntimeException(\sprintf(
                    '%s: the component passed to %s() must be a closure or arrow function written directly in the call, '
                    . 'so that its parameters can be recorded in the manifest. A variable or other expression cannot be read at compile time.',
                    $fqcn,
                    $expr->name->toString(),
                ));
            }
            $expr = $component;
        }

        return $this->isClosure($expr) ? $expr : null;
    }

    private function wrapsComponent(Node\Name $name, NamespaceContext $context): bool
    {
        $function = new Node\Name($context->resolveFunctionName($name));

        return \in_array(\strtolower($function->getLast()), ['fc', 'deferred'], true);
    }

    /**
     * @psalm-assert-if-true Node\Expr\Closure|Node\Expr\ArrowFunction $expr
     * @phpstan-assert-if-true Node\Expr\Closure|Node\Expr\ArrowFunction $expr
     */
    private function isClosure(?Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction;
    }

    /**
     * `fc(key: 'k', component: fn...)` のように、名前付きなら位置は決まらない。
     */
    private function componentArgument(Node\Expr\FuncCall $call): ?Node\Expr
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
     * @param array<Node\Stmt> $statements
     */
    private function topLevelReturn(array $statements): ?Node\Stmt\Return_
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Return_) {
                return $statement;
            }
            if ($statement instanceof Node\Stmt\Namespace_) {
                $nested = $this->topLevelReturn($statement->stmts);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
