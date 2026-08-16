<?php

use Illuminate\Filesystem\Filesystem;

it('creates a configuration file in the current project', function () {
    withinTemporaryProject(function (string $directory) {
        $this->artisan('init')
            ->expectsOutputToContain('Created maintainer.json.')
            ->assertSuccessful();

        expect(json_decode(file_get_contents($directory.'/maintainer.json'), true))->toBe([
            'git' => [
                'diff' => [
                    'output_format' => 'line_by_line',
                ],
            ],
        ]);
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

        expect(json_decode($files->get($path), true))->toBe([
            'git' => [
                'diff' => [
                    'output_format' => 'line_by_line',
                ],
            ],
        ]);
    });
});
