<?php

namespace App\Support;

use Illuminate\Filesystem\Filesystem;

use function Illuminate\Filesystem\join_paths;

final readonly class ProjectPath
{
    public function __construct(private Filesystem $files) {}

    /**
     * Resolve the root directory of the consuming Composer project.
     */
    public function root(): ?string
    {
        $composerAutoloadPath = $GLOBALS['_composer_autoload_path'] ?? null;

        if (is_string($composerAutoloadPath)) {
            $root = $this->findComposerRoot(dirname($composerAutoloadPath));

            if ($root !== null) {
                return $root;
            }
        }

        $workingDirectory = getcwd();

        return $workingDirectory === false
            ? null
            : $this->findComposerRoot($workingDirectory);
    }

    private function findComposerRoot(string $directory): ?string
    {
        $directory = realpath($directory) ?: $directory;

        while (true) {
            if ($this->files->isFile(join_paths($directory, 'composer.json'))) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }
}
