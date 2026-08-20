<?php

use App\Support\Configuration\ConfigurationFilePublisher;
use App\Support\Configuration\PublishableConfiguration;
use App\Support\Quality\LaravelProjectType;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-publisher-'.Str::uuid();
    $this->files->makeDirectory($this->directory, recursive: true);
});

afterEach(function () {
    $this->files->deleteDirectory($this->directory);
});

it('refuses to overwrite a destination unless explicitly allowed', function () {
    $path = $this->directory.'/deploy.php';
    $this->files->put($path, "custom deployment\n");
    $publisher = new ConfigurationFilePublisher($this->files);

    expect(fn () => $publisher->publish(
        PublishableConfiguration::Deployer,
        $this->directory,
        LaravelProjectType::Application,
    ))->toThrow(RuntimeException::class, 'deploy.php already exists and will not be overwritten.')
        ->and($this->files->get($path))->toBe("custom deployment\n");

    $publisher->publish(
        PublishableConfiguration::Deployer,
        $this->directory,
        LaravelProjectType::Application,
        overwrite: true,
    );

    expect($this->files->get($path))->toContain("require 'recipe/laravel.php';");
});
