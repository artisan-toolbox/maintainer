<?php

use App\Support\Release\VersionableClass;
use App\Support\Release\VersionableVersionWriter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('creates a typed version constant while preserving the class contents', function () {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-version-writer-'.Str::uuid();
    $path = $directory.'/ProjectVersion.php';

    $files->makeDirectory($directory);
    $files->put($path, <<<'PHP'
<?php

namespace Fixture;

final class ProjectVersion
{
    public function name(): string
    {
        return 'Maintainer';
    }
}
PHP
    );

    try {
        new VersionableVersionWriter($files)->write(
            new VersionableClass('Fixture\ProjectVersion', $path, false, null),
            '2.0.0-alpha.1',
        );

        expect($files->get($path))
            ->toContain("public const string VERSION = '2.0.0-alpha.1';")
            ->toContain("return 'Maintainer';");
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('updates an existing version constant', function () {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-version-writer-'.Str::uuid();
    $path = $directory.'/ProjectVersion.php';

    $files->makeDirectory($directory);
    $files->put($path, <<<'PHP'
<?php

namespace Fixture;

final class ProjectVersion
{
    public const string VERSION = '1.0.0';
}
PHP
    );

    try {
        new VersionableVersionWriter($files)->write(
            new VersionableClass('Fixture\ProjectVersion', $path, true, '1.0.0'),
            '1.1.0',
        );

        expect($files->get($path))
            ->toContain("public const string VERSION = '1.1.0';")
            ->not->toContain("VERSION = '1.0.0'");
    } finally {
        $files->deleteDirectory($directory);
    }
});
