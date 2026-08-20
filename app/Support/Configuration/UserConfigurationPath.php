<?php

namespace App\Support\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use RuntimeException;

final readonly class UserConfigurationPath
{
    public function __construct(
        private Application $application,
        private Repository $configuration,
    ) {}

    public function path(string $name): string
    {
        return project_path($this->relativePath($name));
    }

    public function relativePath(string $name): string
    {
        return 'config/'.$this->prefix().$name.'.php';
    }

    public function legacyPath(string $filename): string
    {
        return project_path($filename);
    }

    private function prefix(): string
    {
        if ($this->application->environment('production')) {
            return '';
        }

        $prefix = $this->configuration->get('app.user_config_prefix', '');

        throw_unless(
            is_string($prefix) && preg_match('/^[A-Za-z0-9_-]*$/D', $prefix) === 1,
            RuntimeException::class,
            'app.user_config_prefix must contain only letters, numbers, underscores, or hyphens.',
        );

        return $prefix;
    }
}
