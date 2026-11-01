<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * 依存の向き: `polidog/rosanna-state` が本体に依存する。逆の参照を作らない。
 */
final class StateLayerBoundaryTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** 移す前に本体にあった、状態層のクラスと関数 */
    private const array STATE_NAMES = [
        'Action', 'ActionForm', 'ActionEnvelope', 'ActionContext', 'ActionHandler', 'ActionSigner', 'ActionFormExtension',
        'BaseComponent', 'Component', 'ComponentInterface', 'ComponentRegistry', 'ComponentRenderer', 'ComponentState', 'ComponentWrapper',
        'CsrfGuard', 'Defer', 'DeferredEndpoint', 'DeferredManifestCompiler', 'DeferredRegistry', 'AssetInstaller',
        'FunctionComponent', 'FunctionComponentCall', 'HookSupport', 'NativeSession', 'RedirectTarget', 'RendererFactory', 'RenderPass',
        'Request', 'Response', 'RosannaState', 'SessionSnapshots', 'SessionStorage', 'Snapshot', 'SnapshotBehavior', 'SnapshotCarrier',
        'SnapshotPersist', 'SnapshotSerializer', 'SnapshotStorage', 'SnapshotUrl', 'SnapshotVerificationException', 'StateDiff', 'StateKey',
        'StateScope', 'StateStorageInterface', 'StorageType', 'WrapperSearch', 'MemoryStorage',
        'useState', 'useEffect', 'useFormAction', 'useSnapshot', 'component',
    ];

    /** @return array<string, string> パス => 中身 */
    private static function sources(): array
    {
        $sources = [];
        $src = \dirname(__DIR__, 2) . '/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->getExtension() === 'php') {
                $sources[$entry->getPathname()] = (string) \file_get_contents($entry->getPathname());
            }
        }
        self::assertNotEmpty($sources);

        return $sources;
    }

    /** コメントでの言及は参照ではない。文字列の中の `\\` の表記は参照として数える */
    public function testSrcDoesNotReferenceTheStateNamespace(): void
    {
        $offenders = [];
        foreach (self::sources() as $path => $source) {
            $code = '';
            foreach (\token_get_all($source) as $token) {
                if (!\is_array($token)) {
                    $code .= $token;
                } elseif ($token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT) {
                    $code .= $token[1];
                }
            }
            // 移す前の名前空間も見る。残っていれば本体だけで読めないコードになる
            if (\preg_match('/Polidog\\\\+Rosanna\\\\+(State|Http|Protocol|Snapshot|Storage|Component|Installer)(?![A-Za-z0-9_])/', $code) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders);
    }

    /** 名前として書かれたものだけを見る。例外の案内文に `RosannaState` と書くのは参照ではない */
    public function testSrcDoesNotNameAStateClass(): void
    {
        $names = \array_flip(self::STATE_NAMES);
        $offenders = [];
        foreach (self::sources() as $path => $source) {
            $tokens = \token_get_all($source);
            foreach ($tokens as $i => $token) {
                if (!\is_array($token) || !\in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                // メソッドの宣言や呼び出し、名前付き引数は名前が同じだけ
                $previous = $tokens[$i - 1][0] ?? null;
                $beforeSpace = $tokens[$i - 2][0] ?? null;
                if (\in_array($previous, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)
                    || ($previous === \T_WHITESPACE && $beforeSpace === \T_FUNCTION)
                    || ($tokens[$i + 1] ?? null) === ':') {
                    continue;
                }
                $short = \substr((string) \strrchr('\\' . $token[1], '\\'), 1);
                if (isset($names[$short])) {
                    $offenders[] = "{$path}:{$token[2]} {$token[1]}";
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /** `data-rosanna` は状態層のものなので、コメントにも書かない */
    public function testSrcDoesNotMentionDataRosanna(): void
    {
        $offenders = [];
        foreach (self::sources() as $path => $source) {
            if (\stripos($source, 'data-rosanna') !== false) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders);
    }

    public function testComposerDoesNotDependOnTheStateLayer(): void
    {
        $composer = \json_decode((string) \file_get_contents(self::ROOT . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($composer));

        foreach (['require', 'require-dev'] as $section) {
            self::assertArrayNotHasKey('polidog/rosanna-state', $composer[$section] ?? [], $section);
        }
    }

    /** `rosanna-state` のものが配布物に残っていない */
    public function testStateLayerFilesAreGone(): void
    {
        self::assertFileDoesNotExist(self::ROOT . '/public/rosanna.js');
        foreach (['Http', 'Protocol', 'Snapshot', 'Storage', 'Component', 'Installer'] as $directory) {
            self::assertDirectoryDoesNotExist(self::ROOT . "/src/{$directory}");
        }
    }
}
