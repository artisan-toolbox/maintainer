<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;

#[Singleton]
final class MaintainerConfiguration
{
    /** @var array<string, mixed>|null */
    private ?array $items = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly UserConfigurationPath $userConfigurationPath,
        private readonly ProjectEnvironmentLoader $environment,
        private readonly DefaultMaintainerConfiguration $defaults,
        private readonly PhpConfigurationLoader $loader,
        private readonly LegacyJsonConfigurationLoader $legacyLoader,
    ) {}

    /**
     * Return all configured values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items ??= $this->load();
    }

    #[\NoDiscard]
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    public function configMissing(): bool
    {
        return ! $this->files->isFile($this->path())
            && ! $this->files->isFile($this->legacyPath());
    }

    /**
     * Determine whether the configuration contains the given key.
     *
     * @param  string|array<int, string>  $keys
     */
    public function has(string|array $keys): bool
    {
        return Arr::has($this->all(), $keys);
    }

    /**
     * Discard cached values and read the configuration again.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $this->items = null;

        return $this->all();
    }

    public function path(): string
    {
        return $this->userConfigurationPath->path('maintainer');
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        return $this->environment->load(function (): array {
            $defaults = $this->defaults->all();

            if ($this->files->isFile($this->path())) {
                return array_replace_recursive(
                    $defaults,
                    $this->loader->load($this->path(), $this->userConfigurationPath->relativePath('maintainer')),
                );
            }

            if (! $this->files->isFile($this->legacyPath())) {
                return $defaults;
            }

            return array_replace_recursive(
                $defaults,
                $this->legacyLoader->load($this->legacyPath(), 'maintainer.json'),
            );
        });
    }

    private function legacyPath(): string
    {
        return $this->userConfigurationPath->legacyPath('maintainer.json');
    }
}
