<?php

use Illuminate\Filesystem\Filesystem;

function installFakeQualityBinaries(string $directory, Filesystem $files): void
{
    foreach (['pint', 'rector', 'phpstan', 'pest'] as $binary) {
        $windows = PHP_OS_FAMILY === 'Windows';
        $path = $directory.'/vendor/bin/'.$binary.($windows ? '.bat' : '');
        $script = $windows
            ? "@echo off\r\necho {$binary} %*>>\"{$directory}/quality.log\"\r\n"
            : "#!/missing/php\n<?php\nfile_put_contents("
                .var_export($directory.'/quality.log', true)
                .", '"
                .$binary
                ." '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);\n";
        $files->put($path, $script);
        chmod($path, 0755);
    }
}

it('runs project binaries with each existing project configuration', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/rector.php', "<?php\n");
        $files->put($directory.'/phpstan.neon.dist', "parameters:\n");
        $files->put($directory.'/phpunit.xml.dist', "<phpunit/>\n");

        $this->artisan('quality', ['--no-interaction' => true])
            ->expectsOutputToContain('Pint, Rector, PHPStan, and Pest completed successfully.')
            ->assertSuccessful();

        $resolvedDirectory = realpath($directory);
        assert(is_string($resolvedDirectory));
        $normalizedDirectory = str_replace('\\', '/', $resolvedDirectory);
        $qualityLog = str_replace(
            ["\r\n", '\\', '"'],
            ["\n", '/', ''],
            $files->get($directory.'/quality.log'),
        );

        expect($resolvedDirectory)->not->toBeFalse()
            ->and($qualityLog)->toBe(implode("\n", [
                "pint --config {$normalizedDirectory}/pint.json",
                "rector process --config {$normalizedDirectory}/rector.php",
                "phpstan analyse --configuration {$normalizedDirectory}/phpstan.neon.dist --memory-limit=2G",
                "pest --configuration {$normalizedDirectory}/phpunit.xml.dist",
                '',
            ]));
    });
});

it('runs only the selected quality tool', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);
        $files->put($directory.'/phpstan.neon', "parameters:\n");

        $this->artisan('quality', [
            '--tool' => ['phpstan'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('PHPStan completed successfully.')
            ->assertSuccessful();

        $resolvedDirectory = realpath($directory);
        assert(is_string($resolvedDirectory));
        $qualityLog = str_replace(
            ['\\', '"'],
            ['/', ''],
            $files->get($directory.'/quality.log'),
        );

        expect(trim($qualityLog))->toBe(
            'phpstan analyse --configuration '
            .str_replace('\\', '/', $resolvedDirectory)
            .'/phpstan.neon --memory-limit=2G',
        );
    });
});

it('runs multiple selected quality tools in one workflow', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");

        $this->artisan('quality', [
            '--tool' => ['pint', 'pest'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Pint and Pest completed successfully.')
            ->assertSuccessful();

        $resolvedDirectory = realpath($directory);
        assert(is_string($resolvedDirectory));
        $normalizedDirectory = str_replace('\\', '/', $resolvedDirectory);
        $qualityLog = str_replace(
            ["\r\n", '\\', '"'],
            ["\n", '/', ''],
            $files->get($directory.'/quality.log'),
        );

        expect($qualityLog)->toBe(implode("\n", [
            "pint --config {$normalizedDirectory}/pint.json",
            "pest --configuration {$normalizedDirectory}/phpunit.xml",
            '',
        ]));
    });
});

it('rejects an unsupported quality tool', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);

        $this->artisan('quality', [
            '--tool' => ['eslint'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('must be pint, rector, phpstan, or pest')
            ->assertFailed();

        expect($files->exists($directory.'/quality.log'))->toBeFalse();
    });
});

it('fails non-interactively when a project configuration is missing', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);

        $this->artisan('quality', ['--no-interaction' => true])
            ->expectsOutputToContain('Pint configuration is missing.')
            ->assertFailed();

        expect($files->exists($directory.'/pint.json'))->toBeFalse()
            ->and($files->exists($directory.'/quality.log'))->toBeFalse();
    });
});

it('stops when a project quality binary is not installed', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/rector.php', "<?php\n");
        $files->put($directory.'/phpstan.neon', "parameters:\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");

        $this->artisan('quality', ['--no-interaction' => true])
            ->expectsOutputToContain('Pint is not installed in the project.')
            ->assertFailed();
    });
});

it('does not offer an interactive commit in continuous integration', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/rector.php', "<?php\n");
        $files->put($directory.'/phpstan.neon', "parameters:\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        $files->put($directory.'/changed.php', "<?php\n");

        $this->artisan('quality', ['--no-interaction' => true])->assertSuccessful();

        $this->assertCommandNotCalled('commit');
    });
});

it('passes a project-specific memory limit to PHPStan', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/rector.php', "<?php\n");
        $files->put($directory.'/phpstan.neon', "parameters:\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'phpstan' => [
                    'memory_limit' => '4G',
                ],
            ],
        ]);

        $this->artisan('quality', ['--no-interaction' => true])->assertSuccessful();

        $resolvedDirectory = realpath($directory);
        assert(is_string($resolvedDirectory));
        $normalizedDirectory = str_replace('\\', '/', $resolvedDirectory);
        $qualityLog = str_replace(
            ['\\', '"'],
            ['/', ''],
            $files->get($directory.'/quality.log'),
        );

        expect($resolvedDirectory)->not->toBeFalse();
        expect($qualityLog)
            ->toContain('phpstan analyse --configuration '.$normalizedDirectory.'/phpstan.neon --memory-limit=4G');
    });
});

it('rejects an invalid PHPStan memory limit', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/rector.php', "<?php\n");
        $files->put($directory.'/phpstan.neon', "parameters:\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'phpstan' => [
                    'memory_limit' => 'all the RAM',
                ],
            ],
        ]);

        $this->artisan('quality', ['--no-interaction' => true])
            ->expectsOutputToContain('quality.phpstan.memory_limit must be -1')
            ->assertFailed();
    });
});
