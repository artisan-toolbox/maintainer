<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
final readonly class DefaultMaintainerSecrets
{
    public function __construct(
        private Filesystem $files,
        private PhpConfigurationLoader $loader,
        private ProjectEnvironmentLoader $environment,
    ) {}

    public function contents(): string
    {
        $this->environment->load();
        $path = $this->path();
        $this->loader->load($path, 'The default Maintainer secrets file');

        return $this->files->get($path);
    }

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
