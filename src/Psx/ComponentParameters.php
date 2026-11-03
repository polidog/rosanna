<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use function Polidog\Rosanna\Internal\Functions\Psx\allowsNullType;
use function Polidog\Rosanna\Internal\Functions\Psx\returnedComponent;
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
        $component = returnedComponent($statements ?? [], $context, $fqcn);
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
}
