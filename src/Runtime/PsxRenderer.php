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

    /** manifest の成果物を表す ID の項目。`#` で始まる名前のクラスはないので FQCN と重ならない */
    public const BUILD_ID_KEY = '#build-id';

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
     * @throws \RuntimeException ファイルがない、配列を返さない、項目の形が違うとき
     */
    public function loadManifest(string $path): void
    {
        foreach (self::readArrayFile($path) as $fqcn => $entry) {
            if ($fqcn === self::BUILD_ID_KEY) {
                continue;
            }
            if (!is_string($fqcn) || !is_array($entry) || !is_string($entry['file'] ?? null) || !is_array($entry['parameters'] ?? [])) {
                throw new \RuntimeException("Invalid PSX manifest entry in {$path}. Run `vendor/bin/rosanna compile` to regenerate.");
            }
            /** @var array<string, mixed> $entry */
            $this->manifest[$fqcn] = $entry;
            unset($this->loaded[$fqcn]);
        }
    }

    /**
     * manifest の build ID。古い形式なら null。
     *
     * @throws \RuntimeException ファイルがない、配列を返さないとき
     */
    public static function readBuildId(string $path): ?string
    {
        $buildId = self::readArrayFile($path)[self::BUILD_ID_KEY] ?? null;

        return is_string($buildId) ? $buildId : null;
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
     * 読み込み済みの callable。
     *
     * @internal 状態層のため
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
        if ($component instanceof AcceptsArgumentResolver) {
            return $component->withArgumentResolver(fn(array $props): array => $this->argumentResolver->resolve($fqcn, $metadata, $props));
        }

        return fn(array $props): mixed => $component($props, ...$this->argumentResolver->resolve($fqcn, $metadata, $props));
    }

    /**
     * @internal 状態層のため
     * @return array<mixed>
     * @throws \RuntimeException
     */
    public static function readArrayFile(string $path): array
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
