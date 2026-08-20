<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final readonly class DefaultMaintainerConfiguration
{
    public function __construct(
        private PhpConfigurationLoader $loader,
    ) {}

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
