<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\absolutePath;
use function Polidog\Rosanna\Internal\Functions\Psx\buildId;
use function Polidog\Rosanna\Internal\Functions\Psx\cachePathFor;
use function Polidog\Rosanna\Internal\Functions\Psx\collectSourceFiles;
use function Polidog\Rosanna\Internal\Functions\Psx\ensureDirectory;
use function Polidog\Rosanna\Internal\Functions\Psx\manifestEntries;
use function Polidog\Rosanna\Internal\Functions\Psx\modificationTimes;
use function Polidog\Rosanna\Internal\Functions\Psx\readMeta;
use function Polidog\Rosanna\Internal\Functions\Psx\removeStaleDeferredManifest;

use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/** CP-121、CP-132、CP-136、CP-144 */
final class CompileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('psx-compile-functions-');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    public function testCachePathForHashesTheRealPath(): void
    {
        \touch($this->dir . '/A.psx');
        \mkdir($this->dir . '/sub');
        $expected = '/c/' . \sha1($this->dir . '/A.psx') . '.php';

        self::assertSame($expected, cachePathFor('/c', $this->dir . '/A.psx'));
        self::assertSame($expected, cachePathFor('/c/', $this->dir . '/sub/../A.psx'));
        self::assertSame('/c/' . \sha1('/no/such.psx') . '.php', cachePathFor('/c', '/no/such.psx'));
    }

    public function testCollectSourceFilesFindsPsxFilesSortedAndUnique(): void
    {
        \mkdir($this->dir . '/b/c', 0o777, true);
        \touch($this->dir . '/b/Z.psx');
        \touch($this->dir . '/b/c/A.psx');
        \touch($this->dir . '/b/note.txt');
        \touch($this->dir . '/Top.psx');
        \touch($this->dir . '/top.php');
        \symlink($this->dir . '/Top.psx', $this->dir . '/b/Link.psx');

        self::assertSame(
            [$this->dir . '/Top.psx', $this->dir . '/b/Z.psx', $this->dir . '/b/c/A.psx'],
            collectSourceFiles([$this->dir . '/b', $this->dir . '/Top.psx', $this->dir . '/b/c/A.psx', $this->dir . '/top.php', $this->dir . '/missing']),
        );
    }

    public function testModificationTimesOfSourceFiles(): void
    {
        \touch($this->dir . '/A.psx', 1_000);
        \touch($this->dir . '/b.txt', 2_000);

        self::assertSame([$this->dir . '/A.psx' => 1_000], modificationTimes([$this->dir]));
    }

    public function testReadMetaReturnsHashAndRefs(): void
    {
        $path = $this->dir . '/a.php.meta';
        \file_put_contents($path, \json_encode(['hash' => 'h', 'refs' => ['App\\B'], 'source' => '/a.psx']));

        self::assertSame(['hash' => 'h', 'refs' => ['App\\B']], readMeta($path));
    }

    /** @return iterable<string, array{string}> */
    public static function brokenMeta(): iterable
    {
        yield 'not json' => ['not json'];
        yield 'no hash' => ['{"refs":[],"source":"/a.psx"}'];
        yield 'no source' => ['{"hash":"h","refs":[]}'];
        yield 'refs not array' => ['{"hash":"h","refs":"x","source":"/a.psx"}'];
        yield 'ref not string' => ['{"hash":"h","refs":[1],"source":"/a.psx"}'];
    }

    #[DataProvider('brokenMeta')]
    public function testReadMetaReturnsNullForBrokenMeta(string $content): void
    {
        $path = $this->dir . '/a.php.meta';
        \file_put_contents($path, $content);

        self::assertNull(readMeta($path));
    }

    public function testReadMetaReturnsNullWithoutFile(): void
    {
        self::assertNull(readMeta($this->dir . '/missing.meta'));
    }

    public function testManifestEntriesAreSortedAndReadParametersFromCompiled(): void
    {
        $compiled = "<?php\nnamespace App;\nreturn fn (array \$props, ?string \$title) => null;\n";

        self::assertSame(
            [
                "    'App\\\\A' => [",
                "        'file' => " . \var_export(cachePathFor('/c', '/s/A.psx'), true) . ',',
                "        'parameters' => [",
                "            ['kind' => 'props', 'name' => 'props'],",
                "            ['kind' => 'null', 'name' => 'title'],",
                '        ],',
                '    ],',
                "    'App\\\\B' => [",
                "        'file' => " . \var_export(cachePathFor('/c', '/s/B.psx'), true) . ',',
                "        'parameters' => [],",
                '    ],',
            ],
            manifestEntries(['App\\B' => '/s/B.psx', 'App\\A' => '/s/A.psx'], ['/s/A.psx' => $compiled], '/c'),
        );
    }

    public function testBuildIdIsStableAndDependsOnArtifacts(): void
    {
        $id = buildId(['/s/A.psx' => 'src'], ['/s/A.psx' => 'out'], '/c', ['entry']);

        self::assertSame($id, buildId(['/s/A.psx' => 'src'], ['/s/A.psx' => 'out'], '/c', ['entry']));
        self::assertNotSame($id, buildId(['/s/A.psx' => 'src'], ['/s/A.psx' => 'changed'], '/c', ['entry']));
        self::assertNotSame($id, buildId(['/s/A.psx' => 'src'], ['/s/A.psx' => 'out'], '/c', ['other']));
    }

    public function testRemoveStaleDeferredManifest(): void
    {
        $path = $this->dir . '/deferred.php';

        self::assertFalse(removeStaleDeferredManifest($path, false));

        \touch($path);
        self::assertTrue(removeStaleDeferredManifest($path, true));
        self::assertFileExists($path);

        self::assertTrue(removeStaleDeferredManifest($path, false));
        self::assertFileDoesNotExist($path);
    }

    public function testEnsureDirectoryCreatesNestedDirectories(): void
    {
        ensureDirectory($this->dir . '/a/b');
        ensureDirectory($this->dir . '/a/b');

        self::assertDirectoryExists($this->dir . '/a/b');
    }

    public function testEnsureDirectoryThrowsWhenAFileIsInTheWay(): void
    {
        \touch($this->dir . '/file');

        $this->expectException(\RuntimeException::class);
        ensureDirectory($this->dir . '/file/sub');
    }

    public function testAbsolutePathResolvesAgainstCwdAndKeepsMissingParts(): void
    {
        \mkdir($this->dir . '/a');

        self::assertSame($this->dir . '/a', absolutePath('a', $this->dir));
        self::assertSame($this->dir . '/a/new/dir', absolutePath('a/new/dir', $this->dir));
        self::assertSame($this->dir . '/a', absolutePath($this->dir . '/a', '/elsewhere'));
    }
}
