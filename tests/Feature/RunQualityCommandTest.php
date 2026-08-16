<?php

use Illuminate\Filesystem\Filesystem;

function installFakeQualityBinaries(string $directory, Filesystem $files): void
{
    foreach (['pint', 'rector', 'phpstan'] as $binary) {
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

        $this->artisan('quality', ['--no-interaction' => true])
            ->expectsOutputToContain('Pint, Rector, and PHPStan completed successfully.')
            ->assertSuccessful();

        $resolvedDirectory = realpath($directory);

        expect($resolvedDirectory)->not->toBeFalse()
            ->and($files->get($directory.'/quality.log'))->toBe(implode("\n", [
                "pint --config {$resolvedDirectory}/pint.json",
                "rector process --config {$resolvedDirectory}/rector.php",
                "phpstan analyse --configuration {$resolvedDirectory}/phpstan.neon.dist",
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

        $this->artisan('quality', ['--no-interaction' => true])
            ->expectsOutputToContain('Pint is not installed in the project.')
            ->assertFailed();
    });
});
