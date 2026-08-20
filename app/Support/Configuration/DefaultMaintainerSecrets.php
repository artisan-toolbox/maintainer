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
    ) {}

    public function contents(): string
    {
        $path = config_path('maintainer_secrets.php');
        $this->loader->load($path, 'The default Maintainer secrets file');

        return $this->files->get($path);
    }
}
