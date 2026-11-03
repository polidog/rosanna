<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use function Polidog\Rosanna\Internal\Functions\Psx\decodeEntities;
use function Polidog\Rosanna\Internal\Functions\Psx\normalizeText;
use function Polidog\Rosanna\Internal\Functions\Runtime\isRawTextTag;

use Polidog\Rosanna\Psx\Ast\PsxAttribute;
use Polidog\Rosanna\Psx\Ast\PsxCode;
use Polidog\Rosanna\Psx\Ast\PsxElement;
use Polidog\Rosanna\Psx\Ast\PsxText;

/**
 * `<` から 1 つのタグを読み、木にする (§2)。
 *
 * 常に `.psx` の元のソースを位置つきで読むので、エラーの行と列は実際の行になる (CP-108)。
 *
 * @internal
 */
final class PsxParser
{
    /** CP-64 */
    private const array DEFER_NON_STRING_ATTRIBUTES = ['localCache', 'reloadable', 'localCacheTtl'];

    public function __construct(
        private readonly string $source,
        private int $pos,
        private readonly PsxPreProcessor $preProcessor,
    ) {}

    public function position(): int
    {
        return $this->pos;
    }

    public function parseElement(): PsxElement
    {
        $start = $this->pos;
        $this->expect('<');
        $name = $this->readName();

        if ($name === '') {
            $this->expect('>');
            $children = $this->parseChildren();
            $this->consumeFragmentClose();

            return new PsxElement('', [], $children, $start);
        }

        $attributes = $this->parseAttributes();
        $this->skipWhitespace();

        if ($this->peek() === '/') {
            $this->pos++;
            $this->expect('>');
            $element = new PsxElement($name, $attributes, [], $start);
        } else {
            $this->expect('>');
            // CP-55 小文字の style と script だけ。isRawTextTag は小文字で比べるので、名前をそのまま渡す
            $children = isRawTextTag($name)
                ? $this->readRawText($name)
                : $this->parseChildren();
            $this->consumeClosingTag($name);
            $element = new PsxElement($name, $attributes, $children, $start);
        }

        if ($element->isDeferCall()) {
            $this->assertDeferAttributes($element);
        }

        return $element;
    }

    /**
     * @return list<PsxAttribute>
     */
    private function parseAttributes(): array
    {
        $attributes = [];
        while (true) {
            $this->skipWhitespace();
            $offset = $this->pos;
            $c = $this->peek();
            if ($c === '/' || $c === '>' || $c === null) {
                return $attributes;
            }

            $name = $this->readName();
            if ($name === '') {
                // スプレッドや `xlink:href` など。呼び出し側の `>` の確認でエラーになる
                return $attributes;
            }

            $this->skipWhitespace();
            if ($this->peek() !== '=') {
                $attributes[] = new PsxAttribute($name, $offset, null, $offset);
                continue;
            }
            $this->pos++;
            $this->skipWhitespace();

            $valueOffset = $this->pos;
            $c = $this->peek();
            if ($c === '"' || $c === "'") {
                $value = decodeEntities($this->readStringLiteral($c));
            } elseif ($c === '{') {
                $value = $this->readBraceExpression();
                if ($value->isBlank()) {
                    throw $this->error("Attribute '$name' has an empty expression");
                }
            } else {
                throw $this->error("Expected attribute value after '=' for '$name'");
            }
            $attributes[] = new PsxAttribute($name, $offset, $value, $valueOffset);
        }
    }

    /**
     * @return list<PsxElement|PsxText|PsxCode>
     */
    private function parseChildren(): array
    {
        $children = [];

        while (($c = $this->peek()) !== null) {
            if ($c === '<') {
                if (($this->source[$this->pos + 1] ?? '') === '/') {
                    break;
                }
                $children[] = $this->parseElement();
                continue;
            }

            if ($c === '{') {
                $code = $this->readBraceExpression();
                if (!$code->isBlank()) {
                    $children[] = $code;
                }
                continue;
            }

            $length = \strcspn($this->source, '<{', $this->pos);
            $text = \substr($this->source, $this->pos, $length);
            $normalized = normalizeText($text);
            if ($normalized !== '') {
                // CP-51: 正規化の後にデコードするので、`&nbsp;` と `&#32;` の空白は残る
                $children[] = new PsxText(
                    decodeEntities($normalized),
                    $this->pos + \strspn($text, " \t\r\n\f\v"),
                );
            }
            $this->pos += $length;
        }

        return $children;
    }

    /**
     * @return list<PsxText>
     */
    private function readRawText(string $name): array
    {
        if (\preg_match('~</' . $name . '(?=[\s/>])~i', $this->source, $match, \PREG_OFFSET_CAPTURE, $this->pos) !== 1) {
            throw $this->error("Unclosed <$name>");
        }
        $end = $match[0][1];
        $text = \substr($this->source, $this->pos, $end - $this->pos);
        $start = $this->pos;
        $this->pos = $end;

        return $text === '' ? [] : [new PsxText($text, $start, raw: true)];
    }

    private function consumeClosingTag(string $expected): void
    {
        $this->expect('<');
        $this->expect('/');
        $name = $this->readName();
        if ($name !== $expected) {
            throw $this->error("Mismatched closing tag: expected </$expected>, got </$name>");
        }
        $this->skipWhitespace();
        $this->expect('>');
    }

    private function consumeFragmentClose(): void
    {
        if ($this->peek() !== '<') {
            throw $this->error('Expected fragment close `</>`');
        }
        $this->pos += 2;
        if ($this->peek() === '>') {
            $this->pos++;

            return;
        }
        $name = $this->readName();

        throw $this->error($name === ''
            ? 'Expected fragment close `</>`'
            : "Expected fragment close `</>`, got `</$name>`");
    }

    /**
     * CP-10、CP-20
     */
    private function readName(): string
    {
        $length = \strspn($this->source, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-', $this->pos);
        $name = \substr($this->source, $this->pos, $length);
        $this->pos += $length;

        return $name;
    }

    /**
     * CP-22
     */
    private function readStringLiteral(string $quote): string
    {
        $open = $this->pos;
        $this->pos++;
        $value = '';

        while (($c = $this->peek()) !== null) {
            if ($c === $quote) {
                $this->pos++;

                return $value;
            }
            if ($c === '\\' && isset($this->source[$this->pos + 1])) {
                $next = $this->source[$this->pos + 1];
                $value .= $next === $quote || $next === '\\' ? $next : $c . $next;
                $this->pos += 2;
                continue;
            }
            $value .= $c;
            $this->pos++;
        }

        $this->pos = $open;

        throw $this->error('Unterminated attribute string literal');
    }

    private function readBraceExpression(): PsxCode
    {
        $open = $this->pos;
        $code = $this->preProcessor->processExpression($this->source, $open + 1);
        if ($code === null) {
            throw $this->error('Unclosed brace expression');
        }
        $this->pos = $code->end + 1;

        return $code;
    }

    /**
     * CP-64、CP-78: 文字列は `H::defer()` の bool、int の引数に渡ると strict_types の下で TypeError になる。
     * 同名の属性は名前付き引数の重複になり、読み込み時に Fatal error になるのでここで止める。
     */
    private function assertDeferAttributes(PsxElement $element): void
    {
        $end = $this->pos;
        $seen = [];
        foreach ($element->attributes as $attribute) {
            if (\is_string($attribute->value) && \in_array($attribute->name, self::DEFER_NON_STRING_ATTRIBUTES, true)) {
                $this->pos = $attribute->valueOffset;

                throw $this->error("<defer> attribute '{$attribute->name}' must not be a string; write {true} or {60}");
            }
            if (isset($seen[$attribute->name])) {
                $this->pos = $attribute->offset;

                throw $this->error("<defer> attribute '{$attribute->name}' is specified more than once");
            }
            $seen[$attribute->name] = true;
        }
        $this->pos = $end;
    }

    private function skipWhitespace(): void
    {
        $this->pos += \strspn($this->source, " \t\r\n\f\v", $this->pos);
    }

    private function expect(string $char): void
    {
        $c = $this->peek();
        if ($c !== $char) {
            throw $this->error("Expected '$char', got '" . ($c ?? 'EOF') . "'");
        }
        $this->pos++;
    }

    private function peek(): ?string
    {
        return $this->source[$this->pos] ?? null;
    }

    /**
     * CP-106
     */
    private function error(string $message): \RuntimeException
    {
        $before = \substr($this->source, 0, $this->pos);
        $line = \substr_count($before, "\n") + 1;
        $lineStart = \strrpos($before, "\n");
        $prefix = \substr($before, $lineStart === false ? 0 : $lineStart + 1);
        $column = \mb_strlen($prefix, 'UTF-8') + 1;
        // 全角は幅 2 で表示される。mb_strwidth は制御文字を 0 に数えるので、タブは 1 文字として揃える
        $caretOffset = \mb_strwidth(\str_replace("\t", ' ', $prefix), 'UTF-8');
        $sourceLine = \rtrim(\explode("\n", $this->source)[$line - 1] ?? '', "\r");

        return new \RuntimeException(
            "PSX parse error at line $line, column $column: $message\n"
            . "    $sourceLine\n"
            . '    ' . \str_repeat(' ', $caretOffset) . '^',
        );
    }
}
