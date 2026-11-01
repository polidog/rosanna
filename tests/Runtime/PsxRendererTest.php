<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\DefaultPsxArgumentResolver;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Runtime\StackTraceRewriter;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-11、CP-150〜152 */
final class PsxRendererTest extends TestCase
{
    use RendersInScope;

    private string $dir;

    protected function setUp(): void
    {
        RequestLifecycle::reset();
        $this->dir = sys_get_temp_dir() . '/rosanna-psx-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/cache', recursive: true);
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function manifest(array $entries): string
    {
        $path = $this->dir . '/cache/' . PsxRenderer::MANIFEST_FILENAME;
        file_put_contents($path, '<?php return ' . var_export($entries, true) . ';');

        return $path;
    }

    private function compiled(string $name, string $code): string
    {
        $path = "{$this->dir}/{$name}.php";
        file_put_contents($path, $code);

        return $path;
    }

    private function psxWith(string $fqcn, string $code, array $parameters = []): PsxRenderer
    {
        $psx = new PsxRenderer();
        $psx->loadManifest($this->manifest([$fqcn => ['file' => $this->compiled(md5($fqcn), $code), 'parameters' => $parameters]]));

        return $psx;
    }

    public function testRT112UnregisteredIsARuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App\\Missing');
        new PsxRenderer()->render('App\\Missing');
    }

    public function testRT112MissingFileIsARuntimeExceptionWithGuidance(): void
    {
        $psx = new PsxRenderer();
        $psx->loadManifest($this->manifest(['App\\C' => ['file' => $this->dir . '/nope.php', 'parameters' => []]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vendor/bin/rosanna compile');
        $psx->render('App\\C');
    }

    public function testRT112ParseErrorIsWrapped(): void
    {
        $psx = $this->psxWith('App\\C', '<?php this is not php');
        try {
            $psx->render('App\\C');
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('vendor/bin/rosanna compile', $e->getMessage());
            self::assertInstanceOf(\ParseError::class, $e->getPrevious());
        }
    }

    public function testRT112NonCallableReturn(): void
    {
        $psx = $this->psxWith('App\\C', '<?php return 1;');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('callable');
        $psx->render('App\\C');
    }

    public function testRT112CompiledClosuresDoNotInheritTheRendererScope(): void
    {
        $psx = $this->psxWith('App\\C', '<?php return static fn(array $props) => new \Polidog\Rosanna\Runtime\Element("p", [], [self::class]);');
        // self が解決できないこと = PsxRenderer の private に届かないこと
        $this->expectException(\Error::class);
        $psx->render('App\\C');
    }

    public function testRT112NonElementReturn(): void
    {
        $psx = $this->psxWith('App\\C', '<?php return static fn() => "text";');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Element');
        $psx->render('App\\C');
    }

    public function testRT112LoadedCallableIsKept(): void
    {
        $counter = $this->dir . '/count';
        $psx = $this->psxWith('App\\C', '<?php file_put_contents(' . var_export($counter, true) . ', "x", FILE_APPEND); return static fn(array $props) => new \Polidog\Rosanna\Runtime\Element("p", $props);');

        self::assertSame(['a' => 1], $psx->render('App\\C', ['a' => 1])->props);
        self::assertSame(['a' => 2], $psx->render('App\\C', ['a' => 2])->props);
        self::assertSame('x', file_get_contents($counter));
    }

    public function testRT111GeneratedCodeCallsThroughTheScope(): void
    {
        $psx = $this->psxWith('App\\C', '<?php return static fn(array $props) => \Polidog\Rosanna\Html\createElement("p", ["children" => $props["t"]]);');
        $html = self::html(static fn() => RenderContext::scope()->psx->render('App\\C', ['t' => 'v']), self::scope(psx: $psx));
        self::assertSame('<p>v</p>', $html);
    }

    public function testRT113ResolverWorksOnBareCallables(): void
    {
        $psx = $this->psxWith('App\\C', '<?php return static fn(array $props, string $extra) => new \Polidog\Rosanna\Runtime\Element("p", [], [$extra]);', [['kind' => 'props', 'name' => 'props'], ['kind' => 'service', 'name' => 'extra', 'service' => 'X']]);
        $psx->setArgumentResolver(self::resolver(['from-resolver']));

        self::assertSame(['from-resolver'], $psx->render('App\\C')->children);
    }

    public function testRT113RegisteredComponentsUseTheResolverToo(): void
    {
        $psx = new PsxRenderer(self::resolver(['z']));
        $psx->register('App\\C', static fn(array $props, string $extra) => new Element('p', [], [$extra]));
        self::assertSame(['z'], $psx->render('App\\C')->children);
    }

    public function testRT114DefaultResolverAppliesPhpDefaultsAndNull(): void
    {
        $resolver = new DefaultPsxArgumentResolver();
        self::assertSame([], $resolver->resolve('A', [['kind' => 'props', 'name' => 'props']], []));
        self::assertSame([], $resolver->resolve('A', [], []));
        self::assertSame([null], $resolver->resolve('A', [['kind' => 'props', 'name' => 'p'], ['kind' => 'null', 'name' => 'x']], []));
        self::assertSame([null], $resolver->resolve('A', [['kind' => 'props', 'name' => 'p'], ['kind' => 'null', 'name' => 'x'], ['kind' => 'default', 'name' => 'n', 'value' => '1']], []));
    }

    public function testRT114DefaultResolverRendersWithRealDefaults(): void
    {
        $psx = $this->psxWith(
            'App\\C',
            '<?php return static fn(array $props, ?string $x, int $n = 7) => new \Polidog\Rosanna\Runtime\Element("p", [], [$x ?? "null", $n]);',
            [['kind' => 'props', 'name' => 'props'], ['kind' => 'null', 'name' => 'x'], ['kind' => 'default', 'name' => 'n', 'value' => '7']],
        );
        self::assertSame(['null', 7], $psx->render('App\\C')->children);
    }

    public function testRT114DefaultResolverDoesNotResolveServices(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('$repo');
        new DefaultPsxArgumentResolver()->resolve('A', [['kind' => 'props', 'name' => 'p'], ['kind' => 'service', 'name' => 'repo', 'service' => 'R']], []);
    }

    /** @return iterable<string, array{string}> */
    public static function badManifests(): iterable
    {
        yield 'not an array' => ['<?php return 1;'];
        yield 'list' => ['<?php return ["x"];'];
        yield 'string entry (the old format)' => ['<?php return ["App\\\\C" => "/x.php"];'];
        yield 'no file' => ['<?php return ["App\\\\C" => ["parameters" => []]];'];
        yield 'bad parameters' => ['<?php return ["App\\\\C" => ["file" => "/x", "parameters" => 1]];'];
        yield 'parse error' => ['<?php this is not php'];
    }

    #[DataProvider('badManifests')]
    public function testRT115BadManifests(string $code): void
    {
        $path = $this->dir . '/cache/' . PsxRenderer::MANIFEST_FILENAME;
        file_put_contents($path, $code);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('manifest');
        new PsxRenderer()->loadManifest($path);
    }

    /** CP-136 deferred manifest を読むのは状態層で、本体は中身を見ない */
    public function testRT115DeferredManifestIsIgnored(): void
    {
        $path = $this->manifest(['App\\C' => ['file' => $this->compiled('c', '<?php return static fn() => new \Polidog\Rosanna\Runtime\Element("p");'), 'parameters' => []]]);
        file_put_contents(dirname($path) . '/' . PsxRenderer::DEFERRED_MANIFEST_FILENAME, '<?php throw new \DomainException("must not be read");');

        $psx = new PsxRenderer();
        $psx->loadManifest($path);

        self::assertSame('p', $psx->render('App\\C')->type);
    }

    /** CP-144 build ID は項目として読まない */
    public function testRT115BuildIdIsNotAnEntry(): void
    {
        $path = $this->manifest([PsxRenderer::BUILD_ID_KEY => 'abc']);

        new PsxRenderer()->loadManifest($path);

        self::assertSame('abc', PsxRenderer::readBuildId($path));
    }

    /** 古い形式の manifest */
    public function testRT115ManifestWithoutABuildIdHasNull(): void
    {
        self::assertNull(PsxRenderer::readBuildId($this->manifest([])));
    }

    public function testRT115MissingManifest(): void
    {
        $this->expectException(\RuntimeException::class);
        new PsxRenderer()->loadManifest($this->dir . '/none.php');
    }

    public function testCP152UnknownPathsAreLeftAlone(): void
    {
        self::assertSame('/no/such/file.php', StackTraceRewriter::rewriteFile('/no/such/file.php'));
        self::assertSame(__FILE__, StackTraceRewriter::rewriteFile(__FILE__));

        $artifact = $this->dir . '/a.php';
        file_put_contents($artifact . '.meta', 'not json');
        self::assertSame($artifact, StackTraceRewriter::rewriteFile($artifact));
        file_put_contents($artifact . '.meta', '{"source": 1}');
        self::assertSame($artifact, StackTraceRewriter::rewriteFile($artifact));
    }

    public function testCP150RewriteFramesAndFormat(): void
    {
        $artifact = $this->dir . '/a.php';
        file_put_contents($artifact . '.meta', '{"hash":"x","refs":[],"source":"/src/A.psx"}');

        self::assertSame(
            [['file' => '/src/A.psx', 'line' => 3], ['function' => 'f'], ['file' => '/other.php']],
            StackTraceRewriter::rewriteFrames([['file' => $artifact, 'line' => 3], ['function' => 'f'], ['file' => '/other.php']]),
        );

        $text = StackTraceRewriter::formatException(new \LogicException('msg'));
        self::assertStringStartsWith('LogicException: msg at ' . __FILE__ . ':', $text);
        self::assertMatchesRegularExpression('/^#\d+ \{main\}$/m', $text);
    }

    /** @param list<mixed> $arguments */
    private static function resolver(array $arguments): PsxArgumentResolver
    {
        return new class ($arguments) implements PsxArgumentResolver {
            /** @var list<mixed> */
            public array $seen = [];

            /** @param list<mixed> $arguments */
            public function __construct(private readonly array $arguments) {}

            public function resolve(string $fqcn, array $metadata, array $props): array
            {
                $this->seen = [$fqcn, $metadata, $props];

                return $this->arguments;
            }
        };
    }
}
