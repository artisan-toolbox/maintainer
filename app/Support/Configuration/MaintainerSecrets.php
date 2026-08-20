<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

#[Singleton]
final readonly class MaintainerSecrets
{
    public function __construct(
        private Filesystem $files,
        private UserConfigurationPath $userConfigurationPath,
        private ProjectEnvironmentLoader $environment,
        private DefaultMaintainerSecrets $defaults,
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
        throw_if($this->missing(), RuntimeException::class, $this->userConfigurationPath->relativePath('maintainer_secrets').' is missing. Run maintainer config:publish and configure the selected AI provider.');

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

    public function sshKey(): string
    {
        throw_if($this->missing(), RuntimeException::class, $this->userConfigurationPath->relativePath('maintainer_secrets').' is missing. Publish the Maintainer secrets configuration first.');

        $sshKey = $this->load()['ssh_key'] ?? null;

        throw_unless(is_string($sshKey) && $sshKey !== '', RuntimeException::class, 'Maintainer secrets do not contain an encrypted ssh_key. Publish the Maintainer secrets configuration to generate one.');

        return $sshKey;
    }

    public function key(): string
    {
        $key = $this->load()['key'] ?? null;

        throw_unless(is_string($key) && $key !== '', MissingAppKeyException::class, 'No Maintainer encryption key has been specified. Configure maintainer_secrets.key or APP_KEY.');

        return $key;
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
        $this->environment->load();

        $defaults = $this->defaults->all();

        if ($this->files->isFile($this->path())) {
            return array_replace_recursive(
                $defaults,
                $this->loader->load($this->path(), $this->userConfigurationPath->relativePath('maintainer_secrets')),
            );
        }

        if (! $this->files->isFile($this->legacyPath())) {
            return $defaults;
        }

        return array_replace_recursive(
            $defaults,
            $this->legacyLoader->load($this->legacyPath(), 'maintainer_secrets.json'),
        );
    }

    private function legacyPath(): string
    {
        return $this->userConfigurationPath->legacyPath('maintainer_secrets.json');
    }
}
