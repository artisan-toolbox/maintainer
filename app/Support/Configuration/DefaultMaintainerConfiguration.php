<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
final readonly class DefaultMaintainerConfiguration
{
    public function __construct(
        private Filesystem $files,
        private PhpConfigurationLoader $loader,
    ) {}

    /**
     * Return the default configuration as distributed with Maintainer.
     */
    public function contents(): string
    {
        $path = $this->path();
        $this->loader->load($path, 'The default Maintainer configuration file');

        return $this->files->get($path);
    }

    /**
     * Decode the default configuration.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->loader->load($this->path(), 'The default Maintainer configuration file');
    }

    private function path(): string
    {
        return config_path('maintainer.php');
    }
}
