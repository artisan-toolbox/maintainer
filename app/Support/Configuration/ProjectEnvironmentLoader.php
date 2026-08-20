<?php

namespace App\Support\Configuration;

use App\Support\ProjectPath;
use Dotenv\Dotenv;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

#[Singleton]
final class ProjectEnvironmentLoader
{
    /** @var array<string, true> */
    private array $loadedRoots = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly ProjectPath $projectPath,
    ) {}

    /**
     * Load the consuming Composer project's environment without replacing
     * variables already provided by the operating system or Laravel Zero.
     */
    public function load(): void
    {
        $root = $this->projectPath->root();

        if ($root === null || isset($this->loadedRoots[$root])) {
            return;
        }

        if (! $this->files->isFile(join_paths($root, '.env'))) {
            $this->loadedRoots[$root] = true;

            return;
        }

        try {
            Dotenv::createImmutable($root)->safeLoad();
            $this->loadedRoots[$root] = true;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Unable to load the project environment file: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}
