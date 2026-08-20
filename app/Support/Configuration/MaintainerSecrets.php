<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

#[Singleton]
final readonly class MaintainerSecrets
{
    public function __construct(
        private Filesystem $files,
        private UserConfigurationPath $userConfigurationPath,
        private PhpConfigurationLoader $loader,
        private LegacyJsonConfigurationLoader $legacyLoader,
    ) {}

    public function missing(): bool
    {
        return ! $this->files->isFile($this->path())
            && ! $this->files->isFile($this->legacyPath());
    }

    /**
     * Return the connection values for an AI provider.
     *
     * A string provider value is treated as its API key for compatibility
     * with minimal hand-written secrets files.
     *
     * @return array<string, mixed>
     */
    public function aiProvider(string $provider): array
    {
        throw_if($this->missing(), RuntimeException::class, $this->userConfigurationPath->relativePath('maintainer_secrets').' is missing. Run maintainer init and configure the selected AI provider.');

        $decoded = $this->load();

        $providers = $decoded['ai_providers'] ?? null;

        throw_unless(is_array($providers), RuntimeException::class, 'Maintainer secrets must contain an ai_providers array.');

        $configuration = $providers[$provider] ?? null;

        if (is_string($configuration)) {
            return ['key' => $configuration];
        }

        throw_unless(is_array($configuration), RuntimeException::class, "Maintainer secrets do not contain credentials for the {$provider} provider.");

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }

    public function path(): string
    {
        return $this->userConfigurationPath->path('maintainer_secrets');
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->files->isFile($this->path())) {
            return $this->loader->load($this->path(), $this->userConfigurationPath->relativePath('maintainer_secrets'));
        }

        return $this->legacyLoader->load($this->legacyPath(), 'maintainer_secrets.json');
    }

    private function legacyPath(): string
    {
        return $this->userConfigurationPath->legacyPath('maintainer_secrets.json');
    }
}
