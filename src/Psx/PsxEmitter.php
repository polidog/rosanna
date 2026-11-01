<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use Polidog\Rosanna\Psx\Ast\PsxAttribute;
use Polidog\Rosanna\Psx\Ast\PsxCode;
use Polidog\Rosanna\Psx\Ast\PsxElement;
use Polidog\Rosanna\Psx\Ast\PsxRegion;
use Polidog\Rosanna\Psx\Ast\PsxText;

/**
 * 解析した PSX から PHP を生成する (§6)。
 *
 * 出力している行を常に覚えておき、タグと `{式}` を書く前に `.psx` の同じ行まで改行を足す。
 * 改行は足すだけなので、生成コードの行はソースの行より先へ進まない (CP-101、CP-102)。
 * 閉じる `])` は最後の子と同じ行に書く。`{1 +}` の検証エラーが閉じタグではなく式の行を指すようにするため。
 *
 * @internal
 */
final class PsxEmitter
{
    private const string CREATE_ELEMENT = '\\Polidog\\Rosanna\\Html\\createElement';

    private const string RENDER_COMPONENT = '\\Polidog\\Rosanna\\Runtime\\RenderContext::scope()->psx->render';

    private const string DEFER = '\\Polidog\\Rosanna\\Html\\H::defer';

    private string $output = '';

    private int $line = 1;

    private readonly TagOperandLowering $lowering;

    /** @var list<PsxCode> 書き出した `{式}` */
    private array $expressions = [];

    public function __construct(
        private readonly string $source,
        private readonly NamespaceContext $context,
    ) {
        $this->lowering = new TagOperandLowering();
    }

    public function emitFile(PsxCode $code): string
    {
        $this->writeCode($code->code, $code->regions, false);
        \assert(
            \substr_count($this->output, "\n") <= \substr_count($this->source, "\n"),
            'PSX compiler emitted more newlines than the source has.',
        );

        return $this->output;
    }

    /**
     * @return list<PsxCode> 書き出した `{式}`。emitFile() の後に読む
     */
    public function expressions(): array
    {
        return $this->expressions;
    }

    /**
     * ソースに同じ文字列があっても壊れないよう、プレースホルダは文字列のパターンでなく位置で分ける (CP-100)。
     *
     * @param list<PsxRegion> $regions
     * @param int $trimmed $code の先頭から取り除いた長さ
     */
    private function writeCode(string $code, array $regions, bool $isExpression, int $trimmed = 0): void
    {
        if ($regions === []) {
            $this->write($code);

            return;
        }

        [$lowered, $offsets] = $this->lowering->lower(
            $code,
            $isExpression,
            \array_map(static fn(PsxRegion $region): int => $region->codeOffset - $trimmed, $regions),
        );

        $cursor = 0;
        foreach ($regions as $i => $region) {
            $this->write(\substr($lowered, $cursor, $offsets[$i] - $cursor));
            $this->writeElement($region->element);
            $this->syncTo($region->end);
            $cursor = $offsets[$i] + \strlen(PsxPreProcessor::placeholder($i));
        }
        $this->write(\substr($lowered, $cursor));
    }

    private function writeExpression(PsxCode $code): void
    {
        $this->expressions[] = $code;
        $trimmed = \trim($code->code);
        $leading = \strlen($code->code) - \strlen(\ltrim($code->code));
        $this->syncTo($code->start + $leading);
        $this->writeCode($trimmed, $code->regions, true, $leading);

        // CP-32: 閉じる `}` は次の行にあるので、この改行でソースより先へ進むことはない
        if ($this->endsWithLineComment($trimmed)) {
            $this->write("\n");
        }
    }

    private function writeElement(PsxElement $element): void
    {
        $this->syncTo($element->start);

        if ($element->isComponent()) {
            // CP-74
            $fqcn = $this->context->resolve($element->name);
            $this->write(self::RENDER_COMPONENT . "('" . \str_replace('\\', '\\\\', $fqcn) . "', [");
            $this->writeEntries($element, false);
            $this->write('])');

            return;
        }

        if ($element->isDeferCall()) {
            $this->write(self::DEFER . '(');
            foreach ($element->attributes as $i => $attribute) {
                $this->write($i === 0 ? '' : ', ');
                $this->syncTo($attribute->offset);
                $this->write($attribute->name . ': ');
                $this->writeAttributeValue($attribute);
            }
            $this->write(')');

            return;
        }

        // CP-70
        $type = $element->isFragment() ? 'Fragment' : $element->name;
        $this->write(self::CREATE_ELEMENT . "('$type', [");
        $this->writeEntries($element, true);
        $this->write('])');
    }

    private function writeEntries(PsxElement $element, bool $isBuiltin): void
    {
        $separator = '';
        foreach ($element->attributes as $attribute) {
            $this->write($separator);
            $separator = ', ';
            $this->syncTo($attribute->offset);
            // CP-25
            $name = $isBuiltin && $element->name === 'label' && $attribute->name === 'for' ? 'htmlFor' : $attribute->name;
            $this->write("'$name' => ");
            $this->writeAttributeValue($attribute);
        }

        if ($element->children !== []) {
            $this->write($separator . "'children' => ");
            $this->writeChildren($element, $isBuiltin);
        }
    }

    private function writeAttributeValue(PsxAttribute $attribute): void
    {
        $value = $attribute->value;
        if ($value === null) {
            $this->write('true');
        } elseif (\is_string($value)) {
            $this->syncTo($attribute->valueOffset);
            $this->write($this->singleLineLiteral($value));
        } else {
            $this->writeExpression($value);
        }
    }

    private function writeChildren(PsxElement $element, bool $isBuiltin): void
    {
        $children = $element->children;

        if (\count($children) === 1) {
            $child = $children[0];
            if ($child instanceof PsxCode) {
                // CP-73: スプレッドは配列の中でしか書けない。CP-71: それ以外は式の値をそのまま渡す
                if ($child->isSpread()) {
                    $this->write('[');
                    $this->writeExpression($child);
                    $this->write(']');
                } else {
                    $this->writeExpression($child);
                }

                return;
            }
            if (!$isBuiltin) {
                // CP-72
                $this->writeChild($child);

                return;
            }
        }

        $this->write('[');
        foreach ($children as $i => $child) {
            $this->write($i === 0 ? '' : ', ');
            $this->writeChild($child);
        }
        $this->write(']');
    }

    private function writeChild(PsxElement|PsxText|PsxCode $child): void
    {
        if ($child instanceof PsxElement) {
            $this->writeElement($child);
        } elseif ($child instanceof PsxCode) {
            $this->writeExpression($child);
        } else {
            $this->syncTo($child->start);
            $this->write($child->raw ? $this->literal($child->value) : $this->singleLineLiteral($child->value));
        }
    }

    /**
     * CP-75
     */
    private function literal(string $value): string
    {
        return "'" . \addcslashes($value, "'\\") . "'";
    }

    /**
     * CP-102: `&#10;` をデコードした改行をそのまま書くと、ソースより行が増える。
     */
    private function singleLineLiteral(string $value): string
    {
        return \implode(' . "\n" . ', \array_map($this->literal(...), \explode("\n", $value)));
    }

    private function endsWithLineComment(string $code): bool
    {
        $tokens = \token_get_all('<?php ' . $code);
        $last = \end($tokens);

        return \is_array($last) && $last[0] === \T_COMMENT && !\str_starts_with($last[1], '/*');
    }

    private function syncTo(int $offset): void
    {
        $target = \substr_count($this->source, "\n", 0, $offset) + 1;
        if ($target > $this->line) {
            $this->output .= \str_repeat("\n", $target - $this->line);
            $this->line = $target;
        }
    }

    private function write(string $text): void
    {
        $this->output .= $text;
        $this->line += \substr_count($text, "\n");
    }
}
