<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

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

        $component = $this->returnedComponent($statements ?? []);
        if ($component === null) {
            return [];
        }

        $context = NamespaceContext::fromSource($compiled);
        $printer = new Standard();
        $parameters = [];

        foreach ($component->params as $index => $parameter) {
            $name = $parameter->var instanceof Node\Expr\Variable && \is_string($parameter->var->name)
                ? $parameter->var->name
                : '';
            $type = $parameter->type;

            if ($index === 0 && ($type === null || ($type instanceof Node\Identifier && $type->toLowerString() === 'array'))) {
                $parameters[] = ['kind' => 'props', 'name' => $name];
            } elseif (($service = $this->serviceType($type)) !== null) {
                $parameters[] = ['kind' => 'service', 'name' => $name, 'service' => $context->resolveClassName($service)];
            } elseif ($parameter->default !== null) {
                $parameters[] = ['kind' => 'default', 'name' => $name, 'value' => $printer->prettyPrintExpr($parameter->default)];
            } elseif ($this->allowsNull($type)) {
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
     */
    private function returnedComponent(array $statements): Node\Expr\Closure|Node\Expr\ArrowFunction|null
    {
        $expr = $this->topLevelReturn($statements)?->expr;

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && $expr->name->getLast() === 'fc') {
            $expr = $this->componentArgument($expr);
        }

        return $expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction ? $expr : null;
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

    private function serviceType(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?Node\Name
    {
        if ($type instanceof Node\Name) {
            return $type;
        }
        if ($type instanceof Node\NullableType) {
            return $type->type instanceof Node\Name ? $type->type : null;
        }
        if ($type instanceof Node\UnionType && $this->allowsNull($type)) {
            $others = \array_values(\array_filter($type->types, fn(Node $t): bool => !$this->isNull($t)));

            return \count($others) === 1 && $others[0] instanceof Node\Name ? $others[0] : null;
        }

        return null;
    }

    private function allowsNull(Node\Identifier|Node\Name|Node\ComplexType|null $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return true;
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) {
                if ($this->isNull($member)) {
                    return true;
                }
            }
        }

        return $this->isNull($type);
    }

    private function isNull(?Node $type): bool
    {
        return $type instanceof Node\Identifier && $type->toLowerString() === 'null';
    }
}
