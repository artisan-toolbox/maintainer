<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final readonly class DefaultMaintainerSecrets
{
    public function __construct(
        private PhpConfigurationLoader $loader,
        private ProjectEnvironmentLoader $environment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->environment->load();

        return $this->loader->load($this->path(), 'The default Maintainer secrets file');
    }

    private function path(): string
    {
        return config_path('maintainer_secrets.php');
    }
}
