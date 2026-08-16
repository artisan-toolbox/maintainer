<?php

use Illuminate\Filesystem\Filesystem;

function installFakeQualityBinaries(string $directory, Filesystem $files): void
{
    foreach (['pint', 'rector', 'phpstan', 'pest'] as $binary) {
        $path = $directory.'/vendor/bin/'.$binary;
        $files->put($path, "#!/bin/sh\nprintf '%s %s\\n' '".$binary."' \"\$*\" >> '".$directory."/quality.log'\n");
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

        expect($resolvedDirectory)->not->toBeFalse()
            ->and($files->get($directory.'/quality.log'))->toBe(implode("\n", [
                "pint --config {$resolvedDirectory}/pint.json",
                "rector process --config {$resolvedDirectory}/rector.php",
                "phpstan analyse --configuration {$resolvedDirectory}/phpstan.neon.dist --memory-limit=2G",
                "pest --configuration {$resolvedDirectory}/phpunit.xml.dist",
                '',
            ]));
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
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "quality": {
                    "phpstan": {
                        "memory_limit": "4G"
                    }
                }
            }
            JSON.PHP_EOL);

        $this->artisan('quality', ['--no-interaction' => true])->assertSuccessful();

        $resolvedDirectory = realpath($directory);

        expect($resolvedDirectory)->not->toBeFalse();
        expect($files->get($directory.'/quality.log'))
            ->toContain('phpstan analyse --configuration '.$resolvedDirectory.'/phpstan.neon --memory-limit=4G');
    });
});

it('rejects an invalid PHPStan memory limit', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeQualityBinaries($directory, $files);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/rector.php', "<?php\n");
        $files->put($directory.'/phpstan.neon', "parameters:\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "quality": {
                    "phpstan": {
                        "memory_limit": "all the RAM"
                    }
                }
            }
            JSON.PHP_EOL);

        $this->artisan('quality', ['--no-interaction' => true])
            ->expectsOutputToContain('quality.phpstan.memory_limit must be -1')
            ->assertFailed();
    });
});
