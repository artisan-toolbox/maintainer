<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function distributedMaintainerExecutable(): string
{
    return getenv('MAINTAINER_TEST_PHAR') ?: dirname(__DIR__, 2).'/builds/maintainer';
}

it('loads the bundled defaults when checking a consuming project', function () {
    withinTemporaryProject(function (string $directory): void {
        $process = new Process([
            PHP_BINARY, distributedMaintainerExecutable(), 'quality:check', '--tool=pest', '--no-interaction',
        ], $directory);
        $process->mustRun();

        expect($process->getOutput())->toContain('CI checks completed successfully: 0 run, 1 skipped.');
    });
});

it('publishes configuration from the distributed executable in a consuming package', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/composer.json', '{"type":"library"}');
        $inputAdapter = $directory.'/prompt-input.php';
        $files->put($inputAdapter, <<<'ADAPTER'
            <?php

            namespace Illuminate\Console\Concerns;

            function stream_isatty($stream): bool
            {
                return true;
            }

            function windows_os(): bool
            {
                return true;
            }
            ADAPTER);

        $process = new Process([
            PHP_BINARY, '-d', 'auto_prepend_file='.$inputAdapter, distributedMaintainerExecutable(), 'config:publish',
        ], $directory, input: "maintainer,maintainer-secrets,pint,rector,phpstan,pest,deployer\nlaravel-package\nyes\n");
        $process->mustRun();

        expect($process->getOutput())->toContain('Configuration publishing completed.');
        $packageRoot = dirname(__DIR__, 2);

        foreach ([
            'config/maintainer.php' => 'config/maintainer.php',
            'config/maintainer_secrets.php' => 'config/maintainer_secrets.php',
            'deploy.php' => 'resources/deploy.php',
            'pint.json' => 'resources/pint.json',
            'rector.php' => 'resources/laravel-package/rector.php',
            'phpstan.neon' => 'resources/laravel-package/phpstan.neon',
            'phpunit.xml' => 'resources/laravel-package/phpunit.xml',
        ] as $destination => $template) {
            $expected = str_replace("\r\n", "\n", $files->get($packageRoot.'/'.$template));
            expect($files->get($directory.'/'.$destination))->toBe(rtrim($expected)."\n");
        }
    });
});
