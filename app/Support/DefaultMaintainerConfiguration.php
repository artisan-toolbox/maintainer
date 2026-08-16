<?php

namespace App\Support;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use stdClass;

#[Singleton]
final readonly class DefaultMaintainerConfiguration
{
    public function __construct(private Filesystem $files) {}

    /**
     * Return the default configuration as distributed with Maintainer.
     */
    public function contents(): string
    {
        $path = resource_path('maintainer.json');

        throw_unless($this->files->isFile($path), RuntimeException::class, 'The default Maintainer configuration file could not be found.');

        return $this->files->get($path);
    }

    /**
     * Decode the default configuration.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $contents = $this->contents();

        try {
            $decoded = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The default Maintainer configuration contains invalid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        throw_unless($decoded instanceof stdClass, RuntimeException::class, 'The default Maintainer configuration must contain a JSON object.');

        /** @var array<string, mixed> $configuration */
        $configuration = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $configuration;
    }
}
