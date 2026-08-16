<?php

use App\Support\Quality\LaravelProjectType;
use App\Support\Quality\QualityConfigurationManager;
use App\Support\Quality\QualityTool;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-quality-'.Str::uuid();
    $this->files->makeDirectory($this->directory, recursive: true);
});

afterEach(function () {
    $this->files->deleteDirectory($this->directory);
});

it('finds every supported project configuration filename', function (
    QualityTool $tool,
    string $filename,
) {
    $path = $this->directory.DIRECTORY_SEPARATOR.$filename;
    $this->files->put($path, "configuration\n");

    $manager = new QualityConfigurationManager($this->files);

    expect($manager->find($tool, $this->directory))->toBe($path);
})->with([
    'Pint' => [QualityTool::Pint, 'pint.json'],
    'Rector' => [QualityTool::Rector, 'rector.php'],
    'PHPStan' => [QualityTool::PhpStan, 'phpstan.neon'],
    'PHPStan dist suffix' => [QualityTool::PhpStan, 'phpstan.neon.dist'],
    'PHPStan alternate dist suffix' => [QualityTool::PhpStan, 'phpstan.dist.neon'],
    'Pest' => [QualityTool::Pest, 'phpunit.xml'],
    'Pest dist suffix' => [QualityTool::Pest, 'phpunit.xml.dist'],
]);

it('installs application templates with application paths', function () {
    $manager = new QualityConfigurationManager($this->files);

    $pint = $manager->install(QualityTool::Pint, $this->directory, LaravelProjectType::Application);
    $rector = $manager->install(QualityTool::Rector, $this->directory, LaravelProjectType::Application);
    $phpstan = $manager->install(QualityTool::PhpStan, $this->directory, LaravelProjectType::Application);
    $pest = $manager->install(QualityTool::Pest, $this->directory, LaravelProjectType::Application);

    expect($this->files->get($pint))->toContain('"preset": "laravel"')
        ->and($this->files->get($rector))->toContain("__DIR__.'/app'")
        ->and($this->files->get($phpstan))->toContain('- app/')
        ->and($this->files->get($pest))->toContain('<directory suffix=".php">app</directory>');
});

it('installs package templates with package paths', function () {
    $manager = new QualityConfigurationManager($this->files);

    $rector = $manager->install(QualityTool::Rector, $this->directory, LaravelProjectType::Package);
    $phpstan = $manager->install(QualityTool::PhpStan, $this->directory, LaravelProjectType::Package);
    $pest = $manager->install(QualityTool::Pest, $this->directory, LaravelProjectType::Package);

    expect($this->files->get($rector))->toContain("__DIR__.'/src'")
        ->not->toContain("__DIR__.'/app'")
        ->and($this->files->get($phpstan))->toContain('- src/')
        ->not->toContain('- app/')
        ->and($this->files->get($pest))->toContain('<directory suffix=".php">src</directory>')
        ->not->toContain('<directory suffix=".php">app</directory>');
});

it('never overwrites an existing project configuration', function () {
    $path = $this->directory.'/rector.php';
    $this->files->put($path, "<?php // Custom project configuration.\n");
    $manager = new QualityConfigurationManager($this->files);

    expect(fn () => $manager->install(
        QualityTool::Rector,
        $this->directory,
        LaravelProjectType::Application,
    ))->toThrow(RuntimeException::class, 'rector.php already exists and will not be overwritten.')
        ->and($this->files->get($path))->toBe("<?php // Custom project configuration.\n");
});
