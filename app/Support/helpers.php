<?php

use App\Support\Configuration\MaintainerConfiguration;

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
