<?php

namespace App\Support\Configuration;

use App\Support\ProjectPath;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use stdClass;

#[Singleton]
final readonly class MaintainerSecrets
{
    public function __construct(
        private Filesystem $files,
        private ProjectPath $projectPath,
    ) {}

    public function missing(): bool
    {
        return ! $this->files->isFile($this->path());
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
        throw_if($this->missing(), RuntimeException::class, 'maintainer_secrets.json is missing. Run maintainer init and configure the selected AI provider.');

        $contents = $this->files->get($this->path());

        try {
            $decodedObject = json_decode($contents, flags: JSON_THROW_ON_ERROR);
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'maintainer_secrets.json contains invalid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        throw_unless($decodedObject instanceof stdClass && is_array($decoded), RuntimeException::class, 'maintainer_secrets.json must contain a JSON object.');

        $providers = $decoded['ai_providers'] ?? null;

        throw_unless(is_array($providers), RuntimeException::class, 'maintainer_secrets.json must contain an ai_providers object.');

        $configuration = $providers[$provider] ?? null;

        if (is_string($configuration)) {
            return ['key' => $configuration];
        }

        throw_unless(is_array($configuration), RuntimeException::class, "maintainer_secrets.json does not contain credentials for the {$provider} provider.");

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }

    public function path(): string
    {
        $projectRoot = $this->projectPath->root();

        throw_if($projectRoot === null, RuntimeException::class, 'Unable to locate the project root. Run Maintainer inside a Composer project.');

        return $projectRoot.DIRECTORY_SEPARATOR.'maintainer_secrets.json';
    }
}
