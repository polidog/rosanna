<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * 生成コードから呼ばれる PSX コンポーネントの入口 (RT-11)。コンパイラに依存しない。
 * リクエストごとの情報を持たないので、Rosanna の間で使い回せる。
 */
final class PsxRenderer
{
    public const MANIFEST_FILENAME = 'manifest.php';
    public const DEFERRED_MANIFEST_FILENAME = 'deferred-manifest.php';

    private PsxArgumentResolver $argumentResolver;

    /** @var array<string, array<string, mixed>> */
    private array $manifest = [];

    /** @var array<string, callable> */
    private array $loaded = [];

    public function __construct(?PsxArgumentResolver $argumentResolver = null)
    {
        $this->argumentResolver = $argumentResolver ?? new DefaultPsxArgumentResolver();
    }

    public function setArgumentResolver(PsxArgumentResolver $resolver): self
    {
        $this->argumentResolver = $resolver;

        return $this;
    }

    /** manifest を介さず登録する。`Rosanna::registerComponent()` のため */
    public function register(string $fqcn, callable $component): self
    {
        $this->loaded[$fqcn] = $this->bindArguments($fqcn, [], $component);

        return $this;
    }

    /**
     * manifest を読み、同じディレクトリの deferred manifest の項目を返す。呼び出し側が registerDeferred() に渡す (RT-11.5)。
     *
     * @return array<array-key, array{component: string, cacheControl: ?string}> 名前 => 登録の内容
     * @throws \RuntimeException ファイルがない、配列を返さない、項目の形が違うとき
     */
    public function loadManifest(string $path): array
    {
        foreach (self::readArrayFile($path) as $fqcn => $entry) {
            if (!is_string($fqcn) || !is_array($entry) || !is_string($entry['file'] ?? null) || !is_array($entry['parameters'] ?? [])) {
                throw new \RuntimeException("Invalid PSX manifest entry in {$path}. Run `vendor/bin/rosanna compile` to regenerate.");
            }
            /** @var array<string, mixed> $entry */
            $this->manifest[$fqcn] = $entry;
            unset($this->loaded[$fqcn]);
        }

        $deferredPath = dirname($path) . DIRECTORY_SEPARATOR . self::DEFERRED_MANIFEST_FILENAME;
        if (!file_exists($deferredPath)) {
            return [];
        }

        $deferred = [];
        foreach (self::readArrayFile($deferredPath) as $name => $entry) {
            $cacheControl = is_array($entry) ? ($entry['cacheControl'] ?? null) : null;
            // `404` のような数字だけの名前は PHP の配列のキーで整数になる
            if (!is_array($entry) || !is_string($entry['component'] ?? null) || (!is_string($cacheControl) && $cacheControl !== null)) {
                throw new \RuntimeException("Invalid deferred manifest entry in {$deferredPath}. Run `vendor/bin/rosanna compile` to regenerate.");
            }
            $deferred[(string) $name] = ['component' => $entry['component'], 'cacheControl' => $cacheControl];
        }

        return $deferred;
    }

    /**
     * @param array<string, mixed> $props
     * @throws \RuntimeException 未登録か読み込みの失敗
     */
    public function render(string $fqcn, array $props = []): Element
    {
        $component = $this->loaded[$fqcn] ?? $this->load($fqcn);
        $result = $component($props);
        if (!$result instanceof Element) {
            throw new \RuntimeException(sprintf('PSX component %s must return an Element; got %s.', $fqcn, get_debug_type($result)));
        }

        return $result;
    }

    /**
     * 読み込み済みの callable。defer エンドポイントの対象の判定に使う (RT-9.4)。
     *
     * @internal
     */
    public function loaded(string $fqcn): ?callable
    {
        return $this->loaded[$fqcn] ?? null;
    }

    /** @throws \RuntimeException */
    private function load(string $fqcn): callable
    {
        $metadata = $this->manifest[$fqcn] ?? throw new \RuntimeException("PSX component not registered: {$fqcn}");
        /** @var string $file */
        $file = $metadata['file'];

        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException("Compiled PSX file not found for {$fqcn}: {$file}. Run `vendor/bin/rosanna compile` to regenerate.");
        }

        try {
            $component = self::requireIsolated($file);
        } catch (\ParseError $e) {
            throw new \RuntimeException(
                "Compiled PSX file is invalid PHP: {$file}. Run `vendor/bin/rosanna compile` to regenerate. ({$e->getMessage()})",
                previous: $e,
            );
        }

        if (!is_callable($component)) {
            throw new \RuntimeException("PSX file did not return a callable: {$file}. Run `vendor/bin/rosanna compile` to regenerate.");
        }

        /** @var array<mixed> $parameters */
        $parameters = $metadata['parameters'] ?? [];

        return $this->loaded[$fqcn] = $this->bindArguments($fqcn, $parameters, $component);
    }

    /**
     * fc() の内側にも効かせる (RT-11.3)。解決はリゾルバーを呼ぶときに読むので、後から差し替えても効く。
     *
     * @param array<mixed> $metadata manifest の `parameters`
     */
    private function bindArguments(string $fqcn, array $metadata, callable $component): callable
    {
        if ($component instanceof FunctionComponent) {
            return $component->withArgumentResolver(fn(array $props): array => $this->argumentResolver->resolve($fqcn, $metadata, $props));
        }

        return fn(array $props): mixed => $component($props, ...$this->argumentResolver->resolve($fqcn, $metadata, $props));
    }

    /**
     * @return array<mixed>
     * @throws \RuntimeException
     */
    private static function readArrayFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("PSX manifest not found: {$path}. Run `vendor/bin/rosanna compile` to generate it.");
        }

        try {
            $data = self::requireIsolated($path);
        } catch (\ParseError $e) {
            throw new \RuntimeException("PSX manifest is invalid PHP: {$path}. ({$e->getMessage()})", previous: $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException("PSX manifest did not return an array: {$path}. Run `vendor/bin/rosanna compile` to regenerate.");
        }

        return $data;
    }

    /** メソッドの中で require すると、ファイルのクロージャがこのクラスのスコープを持つので外す */
    private static function requireIsolated(string $path): mixed
    {
        $require = \Closure::bind(static fn(string $__path): mixed => require $__path, null, null);

        return $require($path);
    }
}
