<?php

namespace App\Support\Quality;

use App\Support\Configuration\MaintainerConfiguration;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

use function Illuminate\Filesystem\join_paths;

abstract class PackageScriptQualityCommand implements QualityCommand
{
    public function __construct(protected readonly Filesystem $files) {}

    abstract protected function binaryFilename(): string;

    abstract protected function expectedCommand(): string;

    abstract protected function matches(string $script): bool;

    public function availability(string $projectRoot): QualityCommandAvailability
    {
        if (! $this->files->isFile(join_paths($projectRoot, 'package.json'))) {
            return QualityCommandAvailability::unavailable('package.json was not found');
        }

        if (! $this->hasBinary($projectRoot)) {
            return QualityCommandAvailability::unavailable("node_modules/.bin/{$this->binaryFilename()} is not installed");
        }

        return $this->scriptName($projectRoot) === null
            ? QualityCommandAvailability::unavailable("no package.json script runs {$this->expectedCommand()}")
            : QualityCommandAvailability::available();
    }

    public function configurationTool(): ?QualityTool
    {
        return null;
    }

    public function command(
        string $projectRoot,
        ?string $configurationPath,
        MaintainerConfiguration $configuration,
    ): array {
        $script = $this->scriptName($projectRoot)
            ?? throw new RuntimeException("No package.json script runs {$this->expectedCommand()}.");

        return [$this->packageManager($projectRoot), 'run', $script];
    }

    private function scriptName(string $projectRoot): ?string
    {
        $manifest = $this->manifest($projectRoot);
        $scripts = $manifest['scripts'] ?? null;

        if (! is_array($scripts)) {
            return null;
        }

        foreach ($scripts as $name => $script) {
            if (is_string($name) && is_string($script) && $this->matches($script)) {
                return $name;
            }
        }

        return null;
    }

    protected function hasMatchingSegment(string $script, string $pattern): bool
    {
        $segments = preg_split('/\s*(?:&&|\|\||;)\s*/', $script);

        if (! is_array($segments)) {
            return false;
        }

        return array_any($segments, fn ($segment) => preg_match($pattern, trim($segment)) === 1);
    }

    private function packageManager(string $projectRoot): string
    {
        $packageManager = $this->manifest($projectRoot)['packageManager'] ?? null;

        if (is_string($packageManager) && preg_match('/^(npm|pnpm|yarn|bun)@/', $packageManager, $matches) === 1) {
            return $matches[1];
        }

        foreach ([
            'pnpm-lock.yaml' => 'pnpm',
            'yarn.lock' => 'yarn',
            'bun.lock' => 'bun',
            'bun.lockb' => 'bun',
        ] as $lockFile => $manager) {
            if ($this->files->isFile(join_paths($projectRoot, $lockFile))) {
                return $manager;
            }
        }

        return 'npm';
    }

    private function hasBinary(string $projectRoot): bool
    {
        $binary = join_paths($projectRoot, 'node_modules', '.bin', $this->binaryFilename());

        return $this->files->isFile($binary)
            || (PHP_OS_FAMILY === 'Windows' && $this->files->isFile($binary.'.cmd'));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $projectRoot): array
    {
        $path = join_paths($projectRoot, 'package.json');

        try {
            $manifest = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("package.json could not be decoded: {$exception->getMessage()}", previous: $exception);
        }

        throw_unless(is_array($manifest), RuntimeException::class, 'package.json must contain a JSON object.');

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }
}
