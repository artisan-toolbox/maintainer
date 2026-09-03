<?php

use App\Support\Quality\QualityConfigurationManager;
use App\Support\Quality\QualityTool;
use Illuminate\Filesystem\Filesystem;

use function Illuminate\Filesystem\join_paths;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = temporaryTestDirectory('maintainer-quality-');
    $this->files->makeDirectory($this->directory, recursive: true);
});

afterEach(function () {
    $this->files->deleteDirectory($this->directory);
});

it('finds every supported project configuration filename', function (
    QualityTool $tool,
    string $filename,
) {
    $path = join_paths($this->directory, $filename);
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
