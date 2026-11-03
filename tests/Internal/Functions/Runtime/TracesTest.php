<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Runtime;

use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Runtime\formatException;
use function Polidog\Rosanna\Internal\Functions\Runtime\frameCallName;
use function Polidog\Rosanna\Internal\Functions\Runtime\rewriteFile;
use function Polidog\Rosanna\Internal\Functions\Runtime\rewriteFrames;

/** CP-150〜CP-152 */
final class TracesTest extends TestCase
{
    private string $dir;

    private string $artifact;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rosanna-traces-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->artifact = $this->dir . '/a.php';
        file_put_contents($this->artifact . '.meta', '{"hash":"x","refs":[],"source":"/src/A.psx"}');
    }

    protected function tearDown(): void
    {
        unlink($this->artifact . '.meta');
        rmdir($this->dir);
    }

    public function testRewriteFileReadsTheMeta(): void
    {
        self::assertSame('/src/A.psx', rewriteFile($this->artifact));
        self::assertSame('/no/such/file.php', rewriteFile('/no/such/file.php'));
        self::assertSame($this->artifact . '.txt', rewriteFile($this->artifact . '.txt'));
    }

    public function testRewriteFramesKeepsFramesWithoutFile(): void
    {
        self::assertSame(
            [['file' => '/src/A.psx', 'line' => 3], ['function' => 'f']],
            rewriteFrames([['file' => $this->artifact, 'line' => 3], ['function' => 'f']]),
        );
    }

    public function testFrameCallNameRewritesClosureNames(): void
    {
        self::assertSame('{closure:/src/A.psx:4}', frameCallName(['function' => '{closure:' . $this->artifact . ':4}']));
        self::assertSame('App\\C->run', frameCallName(['class' => 'App\\C', 'type' => '->', 'function' => 'run']));
        self::assertSame('', frameCallName([]));
    }

    public function testFormatExceptionIncludesPrevious(): void
    {
        $text = formatException(new \LogicException('outer', previous: new \RuntimeException('inner')));

        self::assertStringStartsWith('LogicException: outer at ' . __FILE__ . ':', $text);
        self::assertStringContainsString("\nCaused by: RuntimeException: inner at " . __FILE__ . ':', $text);
    }
}
