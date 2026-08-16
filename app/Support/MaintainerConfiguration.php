<?php

namespace App\Support;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use JsonException;
use RuntimeException;
use stdClass;

#[Singleton]
final class MaintainerConfiguration
{
    /** @var array<string, mixed>|null */
    private ?array $items = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly ProjectPath $projectPath,
        private readonly DefaultMaintainerConfiguration $defaults,
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
        return ! $this->files->isFile($this->path());
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
        $projectRoot = $this->projectPath->root();

        throw_if($projectRoot === null, RuntimeException::class, 'Unable to locate the project root. Run Maintainer inside a Composer project.');

        return $projectRoot.DIRECTORY_SEPARATOR.'maintainer.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $path = $this->path();
        $defaults = $this->defaults->all();

        if (! $this->files->isFile($path)) {
            return $defaults;
        }

        $contents = $this->files->get($path);

        try {
            $decoded = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'maintainer.json contains invalid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        throw_unless($decoded instanceof stdClass, RuntimeException::class, 'maintainer.json must contain a JSON object.');

        /** @var array<string, mixed> $configuration */
        $configuration = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return array_replace_recursive($defaults, $configuration);
    }
}
