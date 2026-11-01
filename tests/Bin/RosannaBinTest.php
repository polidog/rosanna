<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Bin;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/** `bin/rosanna` は `compile` と `help` だけを持つ (RT-13.9、CP-140〜142) */
final class RosannaBinTest extends TestCase
{
    private const string BIN = __DIR__ . '/../../bin/rosanna';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('rosanna-bin-');
        \mkdir($this->dir . '/components');
        \file_put_contents($this->dir . '/components/Hello.psx', "<?php\nnamespace App;\nreturn fn (array \$props) => <b>x</b>;\n");
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    /**
     * @param list<string> $arguments
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runBin(array $arguments): array
    {
        $process = \proc_open([\PHP_BINARY, self::BIN, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($process);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);

        return ['code' => \proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testBinIsExecutableAndDeclared(): void
    {
        self::assertTrue(\is_executable(self::BIN));
        $composer = \json_decode((string) \file_get_contents(\dirname(self::BIN, 2) . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($composer));
        self::assertSame(['bin/rosanna'], $composer['bin']);
    }

    public function testHelpExitsZeroAndHasNoPublish(): void
    {
        foreach ([[], ['help']] as $arguments) {
            $result = $this->runBin($arguments);

            self::assertSame(0, $result['code']);
            self::assertStringContainsString('Usage:', $result['stdout']);
            self::assertStringNotContainsString('publish', $result['stdout']);
        }
    }

    /** RT-13.9 `publish` は `rosanna-state` のコマンド */
    public function testUnknownCommandShowsHelpAndFails(): void
    {
        foreach (['nope', 'publish'] as $command) {
            $result = $this->runBin([$command]);

            self::assertSame(1, $result['code']);
            self::assertStringContainsString('Usage:', $result['stdout']);
            self::assertStringContainsString($command, $result['stderr']);
        }
    }

    /** CP-142 ヘルプが実装のオプションと一致する */
    public function testHelpListsTheCompileOptions(): void
    {
        $help = $this->runBin(['help'])['stdout'];

        \preg_match_all("/\\\$arg === '(--[a-z]+)'|str_starts_with\\(\\\$arg, '(--[a-z]+=)'\\)/", (string) \file_get_contents(\dirname(self::BIN, 2) . '/src/Psx/CompileCommand.php'), $matches);
        $options = \array_filter([...$matches[1], ...$matches[2]]);
        self::assertNotEmpty($options);
        foreach ($options as $option) {
            self::assertStringContainsString($option, $help);
        }
    }

    /** CP-140 引数と exit code が渡る */
    public function testCompilePassesArgumentsAndTheExitCode(): void
    {
        self::assertSame(1, $this->runBin(['compile', '--check', '--cache=cache', 'components'])['code']);

        $compile = $this->runBin(['compile', '--cache=cache', 'components']);
        self::assertSame(0, $compile['code'], $compile['stdout'] . $compile['stderr']);
        self::assertFileExists($this->dir . '/cache/manifest.php');
        self::assertFileDoesNotExist($this->dir . '/cache/deferred-manifest.php');

        self::assertSame(0, $this->runBin(['compile', '--check', '--cache=cache', 'components'])['code']);
        self::assertSame(1, $this->runBin(['compile', '--chek'])['code']);
    }
}
