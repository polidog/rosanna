<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Psx;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 *
 * 生成した PHP を php-parser で検査する。php-parser のクラスは関数を呼んだときに初めて読み込まれるので、
 * このファイルを読み込んでも php-parser は読み込まれない (CP-2)。
 */

use PhpParser\Error;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use Polidog\Rosanna\Psx\Ast\PsxCode;

/**
 * CP-21、CP-30、CP-73: `{}` の中身は 1 つの式か、1 つのスプレッドに限る。括弧と角括弧の両方で閉じないことを確かめる。
 *
 * @internal
 * @param string $source エラーに行を出すための .psx のソース
 * @throws \RuntimeException
 */
function assertSingleExpression(PsxCode $code, string $source): void
{
    if ($code->isBlank()) {
        return;
    }

    $inArray = parseStatements('<?php [' . $code->code . "\n];");
    $isSingle = $inArray !== null
        && \count($inArray) === 1
        && $inArray[0] instanceof Expression
        && $inArray[0]->expr instanceof Array_
        && \count($inArray[0]->expr->items) === 1;

    if ($isSingle && !$code->isSpread()) {
        $inParentheses = parseStatements('<?php (' . $code->code . "\n);");
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
 * @internal
 * @return array<Stmt>|null 解析できなければ null
 */
function parseStatements(string $code): ?array
{
    try {
        return new ParserFactory()->createForNewestSupportedVersion()->parse($code);
    } catch (Error) {
        return null;
    }
}

/**
 * CP-105、CP-107: 出力の行はソースの行と一致するので、そのまま表示できる。
 *
 * @internal
 * @param string $source エラーに行を出すための .psx のソース
 * @throws \RuntimeException
 */
function assertValidPhp(string $output, string $source): void
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
