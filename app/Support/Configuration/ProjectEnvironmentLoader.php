<?php

namespace App\Support\Configuration;

use App\Support\ProjectPath;
use Closure;
use Dotenv\Dotenv;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

#[Singleton]
final readonly class ProjectEnvironmentLoader
{
    public function __construct(
        private Filesystem $files,
        private ProjectPath $projectPath,
    ) {}

    /**
     * Evaluate a callback with the consuming Composer project's environment
     * without replacing existing variables or leaking project values afterward.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function load(Closure $callback): mixed
    {
        $root = $this->projectPath->root();

        if ($root === null || ! $this->files->isFile(join_paths($root, '.env'))) {
            return $callback();
        }

        $processEnvironment = getenv();
        $environment = $_ENV;
        $server = $_SERVER;

        try {
            Dotenv::createImmutable($root)->safeLoad();
        } catch (Throwable $exception) {
            $this->restore($processEnvironment, $environment, $server);

            throw new RuntimeException(
                "Unable to load the project environment file: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        try {
            return $callback();
        } finally {
            $this->restore($processEnvironment, $environment, $server);
        }
    }

    /**
     * @param  array<string, string>  $processEnvironment
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $server
     */
    private function restore(array $processEnvironment, array $environment, array $server): void
    {
        $currentProcessEnvironment = getenv();

        foreach (array_diff_key($currentProcessEnvironment, $processEnvironment) as $name => $value) {
            putenv($name);
        }

        foreach ($processEnvironment as $name => $value) {
            putenv("{$name}={$value}");
        }

        $_ENV = $environment;
        $_SERVER = $server;
    }
}
