<?php

use App\Support\Configuration\PhpConfigurationLoader;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = temporaryTestDirectory('maintainer-php-config-');
    $this->files->makeDirectory($this->directory, recursive: true);
});

afterEach(function () {
    $this->files->deleteDirectory($this->directory);
});

it('loads associative PHP configuration arrays', function () {
    $path = $this->directory.'/configuration.php';
    $this->files->put($path, "<?php\n\nreturn ['enabled' => true];\n");

    expect(new PhpConfigurationLoader($this->files)->load($path, 'Test configuration'))
        ->toBe(['enabled' => true]);
});

it('wraps PHP configuration loading failures with the source label', function () {
    $path = $this->directory.'/configuration.php';
    $this->files->put($path, "<?php\n\nthrow new RuntimeException('broken');\n");

    expect(fn () => new PhpConfigurationLoader($this->files)->load($path, 'Test configuration'))
        ->toThrow(RuntimeException::class, 'Test configuration could not be loaded: broken');
});
