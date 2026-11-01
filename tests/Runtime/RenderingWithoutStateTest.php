<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/**
 * `H` と PSX の描画で状態層のコードが動かず、セッションが始まらず、`data-rosanna` が出力されない。
 * セッションと読み込まれたクラスはプロセスに残るので、子プロセスで数える
 * (`tests/Psx/Integration/RuntimeWithoutCompilerTest.php` と同じ形)。
 */
#[Group('integration')]
final class RenderingWithoutStateTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('rosanna-no-state-');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    public function testRenderingLoadsNoStateCodeAndStartsNoSession(): void
    {
        $result = $this->render();

        self::assertSame('<main class="m"><b>ok</b><i>h</i></main>', $result['html']);
        self::assertStringNotContainsString('data-rosanna', $result['html']);
        self::assertSame([], $result['stateClasses']);
        self::assertSame([], $result['stateFunctions']);
        self::assertSame([], $result['stateFiles']);
        self::assertSame(\PHP_SESSION_NONE, $result['sessionStatus']);
        self::assertSame('', $result['sessionId']);
        self::assertFalse($result['sessionGlobal']);
        // 読み込まれたものはすべて本体のもの
        self::assertContains('Polidog\\Rosanna\\Runtime\\Renderer', $result['rosannaClasses']);
        foreach ($result['rosannaClasses'] as $class) {
            self::assertTrue(
                \str_starts_with($class, 'Polidog\\Rosanna\\Runtime\\') || \str_starts_with($class, 'Polidog\\Rosanna\\Html\\') || $class === 'Polidog\\Rosanna\\Rosanna',
                $class,
            );
        }
    }

    /** 検出そのものの確認: セッションを始めれば同じ数え方で見つかる */
    public function testStartingASessionIsDetected(): void
    {
        $result = $this->render('session_save_path(' . \var_export($this->dir, true) . '); session_start();');

        self::assertSame(\PHP_SESSION_ACTIVE, $result['sessionStatus']);
        self::assertTrue($result['sessionGlobal']);
    }

    /** 検出そのものの確認: 状態層の名前空間のクラスは同じ数え方で見つかる */
    public function testALoadedStateClassIsDetected(): void
    {
        $result = $this->render('eval("namespace Polidog\\\\Rosanna\\\\State; final class Probe {} function probe() {}");');

        self::assertSame(['Polidog\\Rosanna\\State\\Probe'], $result['stateClasses']);
        self::assertSame(['polidog\\rosanna\\state\\probe'], $result['stateFunctions']);
    }

    /**
     * @return array{html: string, stateClasses: list<string>, stateFunctions: list<string>, stateFiles: list<string>, rosannaClasses: list<string>, sessionStatus: int, sessionId: string, sessionGlobal: bool}
     */
    private function render(string $beforeCounting = ''): array
    {
        \mkdir($this->dir . '/components');
        \file_put_contents($this->dir . '/components/Foo.psx', <<<'PSX'
            <?php
            declare(strict_types=1);
            namespace App;
            return fn (array $props) => <b>{$props['v']}</b>;
            PSX);

        \ob_start();
        $code = new CompileCommand()->run(['--cache=' . $this->dir . '/cache', 'components'], $this->dir);
        $output = (string) \ob_get_clean();
        self::assertSame(0, $code, $output);

        $script = $this->dir . '/script.php';
        \file_put_contents($script, \strtr(<<<'PHP'
            <?php
            declare(strict_types=1);
            require %AUTOLOAD%;

            use Polidog\Rosanna\Html\H;

            $app = new \Polidog\Rosanna\Rosanna();
            $app->loadComponentManifest(%MANIFEST%);
            $html = \Polidog\Rosanna\Runtime\RenderContext::run(
                $app->scope(),
                static fn () => $app->renderElement(H::main(className: 'm', children: [
                    \Polidog\Rosanna\Runtime\RenderContext::scope()->psx->render('App\\Foo', ['v' => 'ok']),
                    H::i(children: 'h'),
                ])),
            );
            %BEFORE_COUNTING%

            $declared = [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()];
            $rosanna = array_values(array_filter($declared, static fn (string $class): bool => str_starts_with($class, 'Polidog\\Rosanna\\')));
            echo json_encode([
                'html' => $html,
                'stateClasses' => array_values(array_filter($rosanna, static fn (string $class): bool => str_starts_with($class, 'Polidog\\Rosanna\\State\\'))),
                'stateFunctions' => array_values(array_filter(get_defined_functions()['user'], static fn (string $name): bool => str_starts_with($name, 'polidog\\rosanna\\state\\'))),
                'stateFiles' => array_values(array_filter(get_included_files(), static fn (string $file): bool => str_contains($file, 'rosanna-state'))),
                'rosannaClasses' => $rosanna,
                'sessionStatus' => session_status(),
                'sessionId' => (string) session_id(),
                'sessionGlobal' => isset($_SESSION),
            ], JSON_THROW_ON_ERROR);
            PHP, [
            '%AUTOLOAD%' => \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            '%MANIFEST%' => \var_export($this->dir . '/cache/manifest.php', true),
            '%BEFORE_COUNTING%' => $beforeCounting,
        ]));

        $process = \proc_open([\PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        self::assertSame(0, \proc_close($process), $stdout . $stderr);

        /** @var array{html: string, stateClasses: list<string>, stateFunctions: list<string>, stateFiles: list<string>, rosannaClasses: list<string>, sessionStatus: int, sessionId: string, sessionGlobal: bool} */
        return \json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
    }
}
