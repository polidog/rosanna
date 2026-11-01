<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use PhpParser\Error;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use Polidog\Rosanna\Psx\Ast\PsxCode;

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

        $this->assertValidPhp($output, $source);
        // 全体が正しくても `{1]); f([2}` のように生成コードの括弧を閉じる形があるので、中身を単独で確かめる
        foreach ($emitter->expressions() as $expression) {
            $this->assertSingleExpression($expression, $source);
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

    /**
     * CP-21、CP-30、CP-73: `{}` の中身は 1 つの式か、1 つのスプレッドに限る。括弧と角括弧の両方で閉じないことを確かめる。
     */
    private function assertSingleExpression(PsxCode $code, string $source): void
    {
        if ($code->isBlank()) {
            return;
        }

        $inArray = $this->parseStatements('<?php [' . $code->code . "\n];");
        $isSingle = $inArray !== null
            && \count($inArray) === 1
            && $inArray[0] instanceof Expression
            && $inArray[0]->expr instanceof Array_
            && \count($inArray[0]->expr->items) === 1;

        if ($isSingle && !$code->isSpread()) {
            $inParentheses = $this->parseStatements('<?php (' . $code->code . "\n);");
            $isSingle = $inParentheses !== null && \count($inParentheses) === 1;
        }

        if (!$isSingle) {
            $offset = $code->start + \strlen($code->code) - \strlen(\ltrim($code->code));
            $line = \substr_count($source, "\n", 0, $offset) + 1;
            $sourceLine = \rtrim(\explode("\n", $source)[$line - 1] ?? '', "\r");

            throw new \RuntimeException("PSX compile error at line $line: {...} must hold a single expression\n    $sourceLine");
        }
    }

    /**
     * @return array<\PhpParser\Node\Stmt>|null 解析できなければ null
     */
    private function parseStatements(string $code): ?array
    {
        try {
            return new ParserFactory()->createForNewestSupportedVersion()->parse($code);
        } catch (Error) {
            return null;
        }
    }

    /**
     * CP-105、CP-107: 出力の行はソースの行と一致するので、そのまま表示できる。
     */
    private function assertValidPhp(string $output, string $source): void
    {
        try {
            new ParserFactory()->createForNewestSupportedVersion()->parse($output);
        } catch (Error $e) {
            $line = $e->getStartLine();
            $sourceLine = \rtrim(\explode("\n", $source)[$line - 1] ?? '', "\r");

            throw new \RuntimeException(
                "PSX compile error at line $line: generated PHP is invalid: {$e->getRawMessage()}\n    $sourceLine",
                previous: $e,
            );
        }
    }
}
