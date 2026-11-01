<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Psx\Ast;

/**
 * PSX の領域をプレースホルダへ置き換えた PHP のコード。ファイル全体か `{式}` の中身を表す。
 *
 * @internal
 */
final readonly class PsxCode
{
    /**
     * @param string $code プレースホルダを含むコード
     * @param list<PsxRegion> $regions 添字がプレースホルダの番号に対応する
     * @param int $start `.psx` での先頭の位置
     * @param int $end `.psx` での終端の位置。`{式}` なら閉じる `}` の位置
     */
    public function __construct(
        public string $code,
        public array $regions,
        public int $start,
        public int $end,
    ) {}

    /** CP-31 */
    public function isBlank(): bool
    {
        return $this->firstMeaningfulToken() === null;
    }

    /** CP-73 */
    public function isSpread(): bool
    {
        $token = $this->firstMeaningfulToken();

        return \is_array($token) && $token[0] === \T_ELLIPSIS;
    }

    /**
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function firstMeaningfulToken(): array|string|null
    {
        foreach (\array_slice(\token_get_all('<?php ' . $this->code), 1) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
