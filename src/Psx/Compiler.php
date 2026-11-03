<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use function Polidog\Rosanna\Internal\Functions\Psx\assertSingleExpression;
use function Polidog\Rosanna\Internal\Functions\Psx\assertValidPhp;

/**
 * 1 つの `.psx` のソースを、行番号の揃った PHP に変換する (§5)。
 */
final class Compiler
{
    /** @var list<string> */
    private array $lastReferences = [];

    /**
     * @throws \RuntimeException 構文エラーか、生成した PHP が不正なとき
     */
    public function compile(string $source): string
    {
        $this->lastReferences = [];

        $code = new PsxPreProcessor()->process($source);
        $context = NamespaceContext::fromSource($code->code);
        $emitter = new PsxEmitter($source, $context);
        $output = $emitter->emitFile($code);
        $this->lastReferences = \array_values(\array_unique($context->getResolvedReferences()));

        assertValidPhp($output, $source);
        // 全体が正しくても `{1]); f([2}` のように生成コードの括弧を閉じる形があるので、中身を単独で確かめる
        foreach ($emitter->expressions() as $expression) {
            assertSingleExpression($expression, $source);
        }

        return $output;
    }

    /**
     * @return list<string> 直前の compile() で解決したコンポーネントの FQCN
     */
    public function getLastReferences(): array
    {
        return $this->lastReferences;
    }
}
