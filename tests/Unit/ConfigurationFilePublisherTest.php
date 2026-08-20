<?php

use App\Support\Configuration\ConfigurationFilePublisher;
use App\Support\Configuration\PublishableConfiguration;
use App\Support\Quality\LaravelProjectType;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = temporaryTestDirectory('maintainer-publisher-');
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

    expect($this->files->get($path))->toContain(
        "require 'recipe/laravel.php';",
        "require 'contrib/npm.php';",
        "import(getenv('MAINTAINER_CONTRIB'));",
        '| Config',
    )->toMatch('/\/\/\s*set\(\'repository\'/')
        ->toMatch('/\/\/\s*\'repository:tag\'/');
});

it('normalizes published configuration formatting', function () {
    $templates = $this->directory.'/templates';
    $project = $this->directory.'/project';
    $this->files->makeDirectory($templates, recursive: true);
    $this->files->makeDirectory($project, recursive: true);
    $this->files->put($templates.'/pint.json', "{  \r\n    \"preset\": \"laravel\"  \r\n}\r\n\r\n");

    $publisher = new ConfigurationFilePublisher(
        $this->files,
        templateRoot: $templates,
    );

    $publisher->publish(
        PublishableConfiguration::Pint,
        $project,
        LaravelProjectType::Application,
    );

    expect($this->files->get($project.'/pint.json'))
        ->toBe("{\n    \"preset\": \"laravel\"\n}\n");
});
