<?php

use App\Support\Git\GitignoreManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-gitignore-'.Str::uuid();
    $this->files->makeDirectory($this->directory, recursive: true);
});

afterEach(function () {
    $this->files->deleteDirectory($this->directory);
});

it('preserves line endings and adds each missing entry once', function () {
    $this->files->put($this->directory.'/.gitignore', "/vendor\r\npint.json\r\n");
    $manager = new GitignoreManager($this->files);

    expect($manager->add($this->directory, ['pint.json', 'deploy.php', 'deploy.php']))
        ->toBe(['deploy.php'])
        ->and($manager->add($this->directory, ['pint.json', 'deploy.php']))
        ->toBe([])
        ->and($this->files->get($this->directory.'/.gitignore'))
        ->toBe("/vendor\r\npint.json\r\ndeploy.php\r\n");
});
