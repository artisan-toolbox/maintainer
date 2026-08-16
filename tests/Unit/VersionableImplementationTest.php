<?php

use App\Support\Release\VersionableImplementation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('returns the versionable class and its current version', function () {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-versionable-'.Str::uuid();

    $files->makeDirectory($directory.'/src', recursive: true);
    $files->put($directory.'/composer.json', <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Fixture\\": "src/"
        }
    }
}
JSON
    );
    $files->put($directory.'/src/ProjectVersion.php', <<<'PHP'
<?php

namespace Fixture;

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

final class ProjectVersion implements Versionable
{
    public const string VERSION = '1.'.'2.3';
}
PHP
    );

    try {
        $versionable = new VersionableImplementation($files)->find($directory);

        expect($versionable)
            ->not->toBeNull()
            ->name->toBe('Fixture\ProjectVersion')
            ->version->toBe('1.2.3');
    } finally {
        $files->deleteDirectory($directory);
    }
});
