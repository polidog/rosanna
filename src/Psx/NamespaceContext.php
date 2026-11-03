<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx;

use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\ParserFactory;

use function Polidog\Rosanna\Internal\Functions\Psx\hasNamespaceDeclaration;

/**
 * ファイルの `namespace` と `use` を覚え、名前を FQCN にする (§4)。
 *
 * @internal
 */
final class NamespaceContext
{
    private string $namespace = '';

    /** @var array<string, string> エイリアス => FQCN */
    private array $uses = [];

    /** @var array<string, string> 小文字のエイリアス => 関数の完全な名前 */
    private array $functionUses = [];

    /** @var list<string> */
    private array $runtimeDeclarations = [];

    /** @var list<string> */
    private array $resolvedReferences = [];

    /**
     * @param string $source PSX の領域をプレースホルダへ置き換えた後のソース (CP-92)
     *
     * @throws \RuntimeException `namespace` の宣言を取り出せないとき
     */
    public static function fromSource(string $source): self
    {
        $context = new self();

        $errors = new Collecting();
        $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($source, $errors);
        if ($statements === null) {
            throw new \RuntimeException(
                'PSX namespace context: source could not be parsed: '
                . \implode('; ', \array_map(static fn(Error $e): string => $e->getMessage(), $errors->getErrors())),
            );
        }
        $context->collect($statements);

        $tokens = \token_get_all($source);
        if ($context->namespace === '' && hasNamespaceDeclaration($tokens)) {
            throw new \RuntimeException(
                'PSX namespace context: the source has a `namespace` declaration, but no namespace could be '
                . 'extracted. Check the declaration for syntax errors.',
            );
        }
        $context->collectRuntimeDeclarations($tokens);

        return $context;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * @return list<string>
     */
    public function getRuntimeDeclarations(): array
    {
        return $this->runtimeDeclarations;
    }

    /**
     * @return list<string> 重複を除かず、解決した順に並ぶ
     */
    public function getResolvedReferences(): array
    {
        return $this->resolvedReferences;
    }

    /**
     * コンポーネントのタグを解決し、参照として記録する (CP-90、CP-93)。
     */
    public function resolve(string $tag): string
    {
        $fqcn = $this->uses[$tag] ?? $this->qualify($tag);
        $this->resolvedReferences[] = $fqcn;

        return $fqcn;
    }

    /**
     * パラメータの型を解決する。参照としては記録しない (CP-93)。
     */
    public function resolveClassName(Node\Name $name): string
    {
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        $parts = $name->getParts();
        if (isset($this->uses[$parts[0]])) {
            $parts[0] = $this->uses[$parts[0]];

            return \implode('\\', $parts);
        }

        return $this->qualify($name->toString());
    }

    /**
     * 呼び出しの関数名を解決する。修飾のない名前には `use function` だけが効く。
     * 未インポートの名前はグローバルへのフォールバックがあるので、実在の関数とは限らない。
     */
    public function resolveFunctionName(Node\Name $name): string
    {
        if (!$name->isUnqualified()) {
            return $this->resolveClassName($name);
        }

        return $this->functionUses[$name->toLowerString()] ?? $this->qualify($name->toString());
    }

    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    /**
     * @param array<Node\Stmt> $statements
     */
    private function collect(array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                if ($statement->name !== null) {
                    $this->namespace = $statement->name->toString();
                }
                $this->collect($statement->stmts);
            } elseif ($statement instanceof Node\Stmt\Use_) {
                // CP-91
                foreach ($statement->uses as $use) {
                    $this->addUse($use, $statement->type, '');
                }
            } elseif ($statement instanceof Node\Stmt\GroupUse) {
                foreach ($statement->uses as $use) {
                    $type = $statement->type === Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $statement->type;
                    $this->addUse($use, $type, $statement->prefix->toString() . '\\');
                }
            }
        }
    }

    private function addUse(Node\UseItem $use, int $type, string $prefix): void
    {
        $alias = $use->getAlias()->toString();
        $fullName = $prefix . $use->name->toString();

        if ($type === Node\Stmt\Use_::TYPE_NORMAL) {
            $this->uses[$alias] = $fullName;
        } elseif ($type === Node\Stmt\Use_::TYPE_FUNCTION) {
            $this->functionUses[\strtolower($alias)] = $fullName;
        }
    }

    /**
     * CP-94
     *
     * @param array<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function collectRuntimeDeclarations(array $tokens): void
    {
        $name = '(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)';
        foreach ($tokens as $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            if (\preg_match("~^(?://|\\#)\\s*@psx-runtime\\s+$name~", $token[1], $match) === 1
                || \preg_match("~@psx-runtime\\s+$name\\s*\\*/~", $token[1], $match) === 1) {
                $this->runtimeDeclarations[] = \ltrim($match[1], '\\');
            }
        }
    }
}
