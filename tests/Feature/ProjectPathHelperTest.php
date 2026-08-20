<?php

use function Illuminate\Filesystem\join_paths;

it('resolves paths from the consuming project root with platform separators', function () {
    withinTemporaryProject(function (string $directory) {
        expect(project_path())->toBe(realpath($directory))
            ->and(project_path('vendor/bin/dep'))->toBe(join_paths(realpath($directory), 'vendor', 'bin', 'dep'))
            ->and(project_path('config\\maintainer.php'))->toBe(join_paths(realpath($directory), 'config', 'maintainer.php'));
    });
});
