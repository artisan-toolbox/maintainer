<?php

use Deployer\Deployer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;

use function Deployer\get;
use function Deployer\import;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-deployer-'.Str::uuid();
    $this->files->makeDirectory($this->directory, recursive: true);
});

afterEach(function () {
    Deployer::resetInstance();
    deleteTemporaryDirectory($this->directory);
});

it('imports the Maintainer Deployer task recipe', function () {
    new Deployer(new Application);

    import(dirname(__DIR__, 2).'/app/Deployer/tasks.php');

    expect(get('recipes'))->toBe(['maintainer']);
});

it('loads the installed task recipe from the published deploy file', function () {
    $packageRoot = dirname(__DIR__, 2);
    $this->files->copy($packageRoot.'/resources/deploy.php', $this->directory.'/deploy.php');

    $process = new Process([
        PHP_BINARY,
        $packageRoot.'/vendor/bin/dep',
        '--file='.$this->directory.'/deploy.php',
        'list',
        '--raw',
    ], $this->directory, [
        'MAINTAINER_TASKS_PATH' => $packageRoot.'/app/Deployer/tasks.php',
    ]);
    $process->mustRun();

    expect($process->getOutput())
        ->toContain('artisan:cache:clear')
        ->toContain('npm:build');
});
