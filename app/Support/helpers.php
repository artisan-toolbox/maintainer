<?php

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
