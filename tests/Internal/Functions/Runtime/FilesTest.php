<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Runtime;

use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Runtime\readArrayFile;
use function Polidog\Rosanna\Internal\Functions\Runtime\requireIsolated;

/** RT-11.5: manifest と成果物の読み込み */
final class FilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rosanna-files-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function file(string $code): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, $code);

        return $path;
    }

    public function testRequireIsolatedClosuresHaveNoScope(): void
    {
        $closure = requireIsolated($this->file('<?php return function () { return isset($this); };'));
        \assert($closure instanceof \Closure);

        $reflection = new \ReflectionFunction($closure);
        self::assertNull($reflection->getClosureScopeClass());
        self::assertNull($reflection->getClosureThis());
        self::assertFalse($closure());
    }

    public function testRequireIsolatedHidesLocalVariables(): void
    {
        self::assertSame(['__path'], requireIsolated($this->file('<?php return array_keys(get_defined_vars());')));
    }

    public function testReadArrayFileReturnsTheArray(): void
    {
        self::assertSame(['a' => 1], readArrayFile($this->file("<?php return ['a' => 1];")));
    }

    public function testReadArrayFileThrowsWithoutFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSX manifest not found');

        readArrayFile($this->dir . '/none.php');
    }

    public function testReadArrayFileThrowsForInvalidPhp(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSX manifest is invalid PHP');

        readArrayFile($this->file('<?php return [;'));
    }

    public function testReadArrayFileThrowsWhenNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSX manifest did not return an array');

        readArrayFile($this->file('<?php return 1;'));
    }
}
