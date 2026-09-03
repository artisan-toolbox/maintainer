<?php

namespace App\Support\Quality;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

use function Illuminate\Filesystem\join_paths;

abstract class ComposerQualityCommand implements QualityCommand
{
    public function __construct(protected readonly Filesystem $files) {}

    abstract protected function binaryFilename(): string;

    public function availability(string $projectRoot): QualityCommandAvailability
    {
        return $this->binaryPath($projectRoot) === null
            ? QualityCommandAvailability::unavailable("vendor/bin/{$this->binaryFilename()} is not installed")
            : QualityCommandAvailability::available();
    }

    protected function requiredBinaryPath(string $projectRoot): string
    {
        return $this->binaryPath($projectRoot)
            ?? throw new RuntimeException("{$this->label()} is not installed in the project.");
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    protected function binaryCommand(string $projectRoot, array $arguments): array
    {
        $binary = $this->requiredBinaryPath($projectRoot);

        return PHP_OS_FAMILY === 'Windows'
            ? [$binary, ...$arguments]
            : [PHP_BINARY, $binary, ...$arguments];
    }

    private function binaryPath(string $projectRoot): ?string
    {
        $binary = join_paths($projectRoot, 'vendor', 'bin', $this->binaryFilename());

        if ($this->files->isFile($binary)) {
            return $binary;
        }

        if (PHP_OS_FAMILY === 'Windows' && $this->files->isFile($binary.'.bat')) {
            return $binary.'.bat';
        }

        return null;
    }
}
