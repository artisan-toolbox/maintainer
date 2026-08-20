<?php

namespace App\Support\Configuration;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

final readonly class PhpConfigurationLoader
{
    public function __construct(private Filesystem $files) {}

    /**
     * @return array<string, mixed>
     */
    public function load(string $path, string $label): array
    {
        throw_unless(
            $this->files->isFile($path),
            RuntimeException::class,
            "{$label} could not be found.",
        );

        try {
            $configuration = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "{$label} could not be loaded: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        throw_unless(
            is_array($configuration) && ($configuration === [] || ! array_is_list($configuration)),
            RuntimeException::class,
            "{$label} must return an associative array.",
        );

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }
}
