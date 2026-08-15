<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

function withinTemporaryProject(
    Closure $callback,
    string $workingDirectory = '.',
    bool $exposeComposerProxy = true,
): void {
    $files = new Filesystem;
    $originalWorkingDirectory = getcwd();
    $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-'.Str::uuid();
    $hadComposerAutoloadPath = array_key_exists('_composer_autoload_path', $GLOBALS);
    $originalComposerAutoloadPath = $GLOBALS['_composer_autoload_path'] ?? null;

    $files->makeDirectory($temporaryDirectory.'/vendor/bin', recursive: true);
    $files->put($temporaryDirectory.'/composer.json', "{}\n");
    $files->put($temporaryDirectory.'/vendor/autoload.php', "<?php\n");

    if ($workingDirectory !== '.') {
        $files->makeDirectory($temporaryDirectory.'/'.$workingDirectory, recursive: true, force: true);
    }

    if ($exposeComposerProxy) {
        $GLOBALS['_composer_autoload_path'] = $temporaryDirectory.'/vendor/autoload.php';
    } else {
        unset($GLOBALS['_composer_autoload_path']);
    }

    chdir($temporaryDirectory.'/'.$workingDirectory);

    try {
        $callback($temporaryDirectory, $files);
    } finally {
        chdir($originalWorkingDirectory);

        if ($hadComposerAutoloadPath) {
            $GLOBALS['_composer_autoload_path'] = $originalComposerAutoloadPath;
        } else {
            unset($GLOBALS['_composer_autoload_path']);
        }

        $files->deleteDirectory($temporaryDirectory);
    }
}

it('creates a configuration file in the current project', function () {
    withinTemporaryProject(function (string $directory) {
        $this->artisan('init')
            ->expectsOutputToContain('Created maintainer.json.')
            ->assertSuccessful();

        expect(file_get_contents($directory.'/maintainer.json'))->toBe("{}\n");
    });
});

it('creates the configuration in the project root when run from vendor bin', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('init')->assertSuccessful();

        expect($files->exists($directory.'/maintainer.json'))->toBeTrue()
            ->and($files->exists($directory.'/vendor/bin/maintainer.json'))->toBeFalse();
    }, workingDirectory: 'vendor/bin');
});

it('finds the project root from a nested directory without Composer proxy metadata', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('init')->assertSuccessful();

        expect($files->exists($directory.'/maintainer.json'))->toBeTrue()
            ->and($files->exists($directory.'/packages/example/maintainer.json'))->toBeFalse();
    }, workingDirectory: 'packages/example', exposeComposerProxy: false);
});

it('fails when run outside a Composer project', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->delete($directory.'/composer.json');

        $this->artisan('init')
            ->expectsOutputToContain('Unable to locate the project root.')
            ->assertFailed();

        expect($files->exists($directory.'/maintainer.json'))->toBeFalse();
    }, exposeComposerProxy: false);
});

it('does not overwrite an existing configuration file', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/maintainer.json';
        $files->put($path, "{\n    \"existing\": true\n}\n");

        $this->artisan('init')
            ->expectsOutputToContain('maintainer.json already exists.')
            ->assertFailed();

        expect($files->get($path))->toBe("{\n    \"existing\": true\n}\n");
    });
});

it('overwrites an existing configuration file when forced', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/maintainer.json';
        $files->put($path, "{\n    \"existing\": true\n}\n");

        $this->artisan('init', ['--force' => true])
            ->expectsOutputToContain('Created maintainer.json.')
            ->assertSuccessful();

        expect($files->get($path))->toBe("{}\n");
    });
});
