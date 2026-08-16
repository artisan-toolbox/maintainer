<?php

use App\Support\Release\GitHubReleaseSource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fakes\FakeGitHubReleaseSource;

/**
 * @param  Closure(string, Filesystem): void  $callback
 */
function withinTemporaryReleaseProject(Closure $callback): void
{
    $files = new Filesystem;
    $originalWorkingDirectory = getcwd();
    $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-release-'.Str::uuid();
    $hadComposerAutoloadPath = array_key_exists('_composer_autoload_path', $GLOBALS);
    $originalComposerAutoloadPath = $GLOBALS['_composer_autoload_path'] ?? null;

    $files->makeDirectory($temporaryDirectory.'/vendor', recursive: true);
    $files->makeDirectory($temporaryDirectory.'/src', recursive: true);
    $files->put($temporaryDirectory.'/composer.json', <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Fixture\\": "src/"
        }
    }
}
JSON
    );
    $files->put($temporaryDirectory.'/src/ProjectVersion.php', <<<'PHP'
<?php

namespace Fixture;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

final class ProjectVersion implements Versionable
{
    public const string VERSION = '1.0.0';
}
PHP
    );
    $files->put($temporaryDirectory.'/.gitignore', "/vendor/\n");
    $files->put($temporaryDirectory.'/example.txt', "committed\n");
    $files->put($temporaryDirectory.'/vendor/autoload.php', "<?php\n");

    foreach ([
        ['init', '--initial-branch=1.x'],
        ['config', 'user.name', 'Maintainer Tests'],
        ['config', 'user.email', 'maintainer@example.com'],
        ['config', 'core.autocrlf', 'false'],
        ['add', '.'],
        ['commit', '-m', 'Create fixture'],
    ] as $arguments) {
        new Process(['git', ...$arguments], $temporaryDirectory)->mustRun();
    }

    $GLOBALS['_composer_autoload_path'] = $temporaryDirectory.'/vendor/autoload.php';
    $releaseSource = new FakeGitHubReleaseSource;
    app()->instance(GitHubReleaseSource::class, $releaseSource);
    app()->instance(FakeGitHubReleaseSource::class, $releaseSource);
    chdir($temporaryDirectory);

    try {
        $callback($temporaryDirectory, $files);
    } finally {
        chdir($originalWorkingDirectory);

        if ($hadComposerAutoloadPath) {
            $GLOBALS['_composer_autoload_path'] = $originalComposerAutoloadPath;
        } else {
            unset($GLOBALS['_composer_autoload_path']);
        }

        $files->deleteDirectory($temporaryDirectory);
    }
}

it('registers the create release command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)
        ->toHaveKey('release:create')
        ->and($commands['release:create']->getDescription())
        ->toBe('Create a new GitHub release for the project');
});

it('continues when the Git working tree is clean', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $this->artisan('release:create')
            ->expectsOutputToContain('Versionable class')
            ->expectsOutputToContain('Current version')
            ->expectsOutputToContain('Release branch')
            ->expectsOutputToContain('Latest GitHub version')
            ->expectsOutputToContain('Selected version')
            ->assertSuccessful();

        expect($files->get($directory.'/src/ProjectVersion.php'))
            ->toContain("public const string VERSION = '1.0.1';");
    });
});

it('rejects a dirty Git working tree', function (string $change) {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files) use ($change): void {
        if ($change === 'untracked') {
            $files->put($directory.'/untracked.txt', "untracked\n");
        } else {
            $files->put($directory.'/example.txt', "{$change}\n");

            if ($change === 'staged') {
                new Process(['git', 'add', 'example.txt'], $directory)->mustRun();
            }
        }

        $this->artisan('release:create')
            ->expectsOutputToContain('The Git working tree is not clean.')
            ->assertFailed();
    });
})->with(['staged', 'unstaged', 'untracked']);

it('rejects a project without a versionable class in its base namespace', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->delete($directory.'/src/ProjectVersion.php');
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Remove version class'], $directory)->mustRun();

        $this->artisan('release:create')
            ->expectsOutputToContain('No class directly in a production PSR-4 namespace implements')
            ->assertFailed();
    });
});

it('rejects a versionable class in a nested namespace', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/src/ProjectVersion.php', <<<'PHP'
<?php

namespace Fixture\Maintainer;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

final class ProjectVersion implements Versionable
{
    public const string VERSION = '1.0.0';
}
PHP
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Move version class'], $directory)->mustRun();

        $this->artisan('release:create')
            ->expectsOutputToContain('No class directly in a production PSR-4 namespace implements')
            ->assertFailed();
    });
});

it('rejects a versionable class without a public typed version constant', function (string $declaration) {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files) use ($declaration): void {
        $files->put($directory.'/src/ProjectVersion.php', <<<PHP
<?php

namespace Fixture;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

final class ProjectVersion implements Versionable
{
    {$declaration}
}
PHP
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Change version constant'], $directory)->mustRun();

        $this->artisan('release:create')
            ->expectsOutputToContain('Fixture\\ProjectVersion must declare public const string VERSION.')
            ->assertFailed();
    });
})->with([
    'untyped' => "public const VERSION = '1.0.0';",
    'private' => "private const string VERSION = '1.0.0';",
    'wrong type' => 'public const int VERSION = 1;',
]);

it('creates the version constant when the versionable class does not declare one', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/src/ProjectVersion.php', <<<'PHP'
<?php

namespace Fixture;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

final class ProjectVersion implements Versionable
{
    //
}
PHP
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Remove version constant'], $directory)->mustRun();

        $this->artisan('release:create')
            ->expectsOutputToContain('Current version')
            ->expectsOutputToContain('Version file')
            ->assertSuccessful();

        expect($files->get($directory.'/src/ProjectVersion.php'))
            ->toContain("public const string VERSION = '1.0.1';");
    });
});

it('accepts a valid versionable class when another implementation is incomplete', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/src/IncompleteVersion.php', <<<'PHP'
<?php

namespace Fixture;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

final class IncompleteVersion implements Versionable
{
    //
}
PHP
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Add incomplete version class'], $directory)->mustRun();

        $this->artisan('release:create')
            ->assertSuccessful();
    });
});

it('rejects a versionable class with an unsupported semantic version', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/src/ProjectVersion.php', <<<'PHP'
<?php

namespace Fixture;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

final class ProjectVersion implements Versionable
{
    public const string VERSION = '1.0.0-rc.1';
}
PHP
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Use unsupported prerelease'], $directory)->mustRun();

        $this->artisan('release:create')
            ->expectsOutputToContain('VERSION must use MAJOR.MINOR.PATCH with an optional alpha or beta prerelease.')
            ->assertFailed();
    });
});

it('rejects a branch outside the major release pattern', function () {
    withinTemporaryReleaseProject(function (string $directory): void {
        new Process(['git', 'branch', '-m', 'main'], $directory)->mustRun();

        $this->artisan('release:create')
            ->expectsOutputToContain('The branch main is not a release branch.')
            ->assertFailed();
    });
});

it('starts a major at zero when GitHub has no valid release for the branch', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        resolve(FakeGitHubReleaseSource::class)->releases = [];
        $files->put(
            $directory.'/src/ProjectVersion.php',
            str_replace("    public const string VERSION = '1.0.0';\n", '', $files->get($directory.'/src/ProjectVersion.php')),
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Remove initial version'], $directory)->mustRun();

        $this->artisan('release:create')
            ->expectsOutputToContain('No valid release found')
            ->assertSuccessful();

        expect($files->get($directory.'/src/ProjectVersion.php'))
            ->toContain("public const string VERSION = '1.0.0';");
    });
});

it('rejects non-interactive version selection', function () {
    withinTemporaryReleaseProject(function (): void {
        $this->artisan('release:create', ['--no-interaction' => true])
            ->expectsOutputToContain('requires interactive input to select the next GitHub release version')
            ->assertFailed();
    });
});
