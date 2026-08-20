<?php

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\ProjectPath;

use function Illuminate\Filesystem\join_paths;

if (! function_exists('project_path')) {
    /**
     * Resolve a path relative to the consuming Composer project.
     */
    function project_path(string $path = ''): string
    {
        $root = resolve(ProjectPath::class)->root();

        throw_if($root === null, RuntimeException::class, 'Unable to locate the project root. Run Maintainer inside a Composer project.');

        if ($path === '') {
            return $root;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return join_paths($root, ltrim($path, DIRECTORY_SEPARATOR));
    }
}

if (! function_exists('maintainer_config')) {
    /**
     * Read a value from the consuming project's Maintainer configuration.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    function maintainer_config(?string $key = null, mixed $default = null): mixed
    {
        $configuration = resolve(MaintainerConfiguration::class);

        return $key === null
            ? $configuration->all()
            : $configuration->get($key, $default);
    }
}

if (! function_exists('maintainer_config_missing')) {
    /**
     * Determine whether the consuming project has no Maintainer configuration file.
     */
    function maintainer_config_missing(): bool
    {
        return resolve(MaintainerConfiguration::class)->configMissing();
    }
}
