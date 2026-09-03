<?php

use App\Support\Release\VersionableImplementation;
use ArtisanToolbox\Maintainer\Maintainer;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;
use Illuminate\Filesystem\Filesystem;

it('discovers Maintainer as its own versionable class', function () {
    $versionable = new VersionableImplementation(new Filesystem)->find(dirname(__DIR__, 2));

    expect($versionable)
        ->not->toBeNull()
        ->name->toBe(Maintainer::class)
        ->and($versionable->implements(BeforeVersioning::class))->toBeTrue()
        ->and($versionable->implements(WithReadmeBadgeVersion::class))->toBeTrue();
});

it('returns the versionable class and its current version', function () {
    $files = new Filesystem;
    $directory = temporaryTestDirectory('maintainer-versionable-');

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

it('discovers a versionable class from the production Composer classmap', function () {
    $files = new Filesystem;
    $directory = temporaryTestDirectory('maintainer-versionable-classmap-');

    $files->makeDirectory($directory.'/package', recursive: true);
    $files->put($directory.'/composer.json', <<<'JSON'
{
    "autoload": {
        "classmap": [
            "package/ProjectVersion.php"
        ]
    }
}
JSON
    );
    $files->put($directory.'/package/ProjectVersion.php', <<<'PHP'
<?php

namespace Fixture\Release;

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

final class ProjectVersion implements Versionable
{
    public const string VERSION = '2.1.0';
}
PHP
    );

    try {
        $versionable = new VersionableImplementation($files)->find($directory);

        expect($versionable)
            ->not->toBeNull()
            ->name->toBe('Fixture\Release\ProjectVersion')
            ->version->toBe('2.1.0');
    } finally {
        $files->deleteDirectory($directory);
    }
});
