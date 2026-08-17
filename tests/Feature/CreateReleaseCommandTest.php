<?php

use App\Ai\Agents\ReleaseChangelogAgent;
use App\Ai\Agents\ReleaseDiffSummaryAgent;
use App\Ai\Agents\ReleaseNotesAgent;
use App\Ai\Agents\ReleaseVersionAgent;
use App\Support\Ai\ReleaseChangeAnalyzer;
use App\Support\BrowserLauncher;
use App\Support\Release\GitCliReleaseRepository;
use App\Support\Release\GitHubReleasePublisher;
use App\Support\Release\GitHubReleaseSource;
use App\Support\Release\ReleaseChangeSet;
use App\Support\Release\ReleaseDiffReviewer;
use App\Support\Release\ReleaseGitRepository;
use App\Support\Release\ReleaseVersionSelector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\Process\Process;
use Tests\Fakes\FakeBrowserLauncher;
use Tests\Fakes\FakeGitHubReleasePublisher;
use Tests\Fakes\FakeGitHubReleaseSource;
use Tests\Fakes\FakeReleaseDiffReviewer;
use Tests\Fakes\FakeReleaseGitRepository;
use Tests\Fakes\FakeReleaseVersionSelector;

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

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

final class ProjectVersion implements Versionable
{
    public const string VERSION = '1.0.0';
}
PHP
    );
    $files->put($temporaryDirectory.'/.gitignore', "/vendor/\nmaintainer_secrets.json\n");
    $files->put($temporaryDirectory.'/example.txt', "committed\n");
    $files->put($temporaryDirectory.'/README.md', "# Fixture\n\nFixture documentation.\n");
    $files->put($temporaryDirectory.'/vendor/autoload.php', "<?php\n");
    $files->put($temporaryDirectory.'/maintainer_secrets.json', <<<'JSON'
{
    "ai_providers": {
        "openai": {
            "key": "test-key"
        }
    }
}
JSON
    );

    foreach ([
        ['init', '--initial-branch=1.x'],
        ['config', 'user.name', 'Maintainer Tests'],
        ['config', 'user.email', 'maintainer@example.com'],
        ['config', 'core.autocrlf', 'false'],
        ['add', '.'],
        ['commit', '-m', 'Create fixture'],
        ['tag', '1.0.0'],
    ] as $arguments) {
        new Process(['git', ...$arguments], $temporaryDirectory)->mustRun();
    }

    $GLOBALS['_composer_autoload_path'] = $temporaryDirectory.'/vendor/autoload.php';
    $releaseSource = new FakeGitHubReleaseSource;
    app()->instance(GitHubReleaseSource::class, $releaseSource);
    app()->instance(FakeGitHubReleaseSource::class, $releaseSource);
    $git = new FakeReleaseGitRepository;
    $publisher = new FakeGitHubReleasePublisher;
    app()->instance(ReleaseGitRepository::class, $git);
    app()->instance(FakeReleaseGitRepository::class, $git);
    app()->instance(GitHubReleasePublisher::class, $publisher);
    app()->instance(FakeGitHubReleasePublisher::class, $publisher);
    $diffReviewer = new FakeReleaseDiffReviewer;
    app()->instance(ReleaseDiffReviewer::class, $diffReviewer);
    app()->instance(FakeReleaseDiffReviewer::class, $diffReviewer);
    $versionSelector = new FakeReleaseVersionSelector;
    app()->instance(ReleaseVersionSelector::class, $versionSelector);
    app()->instance(FakeReleaseVersionSelector::class, $versionSelector);
    $browser = new FakeBrowserLauncher;
    app()->instance(BrowserLauncher::class, $browser);
    app()->instance(FakeBrowserLauncher::class, $browser);
    ReleaseVersionAgent::fake([[
        'release_increment' => 'patch',
        'justification' => 'The diff contains backwards-compatible fixes and maintenance.',
    ]]);
    ReleaseDiffSummaryAgent::fake([[
        'summary' => 'The release workflow changes project versioning behavior.',
    ]]);
    ReleaseNotesAgent::fake([[
        'title' => 'Improve the release workflow',
        'body' => "## Changed\n\nImproved the release workflow.",
    ]]);
    ReleaseChangelogAgent::fake([[
        'entries' => [[
            'type' => 'feat',
            'hash' => 'abc1234',
            'title' => 'Improve the release workflow',
            'description' => 'Adds a complete and documented release workflow.',
        ]],
    ]]);
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

        deleteTemporaryDirectory($temporaryDirectory);
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
            ->toContain("public const string VERSION = '1.0.1';")
            ->and($files->get($directory.'/CHANGELOG.md'))->toContain('## [1.0.1] - ')
            ->and(resolve(FakeReleaseGitRepository::class)->staged)->toBeTrue()
            ->and(resolve(FakeReleaseGitRepository::class)->committed)->toBeTrue()
            ->and(resolve(FakeReleaseGitRepository::class)->pushed)->toBeTrue()
            ->and(resolve(FakeGitHubReleasePublisher::class)->published)->toMatchArray([
                'version' => '1.0.1',
                'target' => '1.x',
                'prerelease' => false,
            ]);

        ReleaseChangelogAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Fragment 1: The release workflow changes project versioning behavior.'));
        ReleaseNotesAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '[feat] abc1234 Improve the release workflow'));
    });
});

it('identifies release change analysis failures and rolls back the worktree', function () {
    withinTemporaryReleaseProject(function (): void {
        app()->instance(ReleaseChangeAnalyzer::class, new class implements ReleaseChangeAnalyzer
        {
            public function analyze(string $provider, ReleaseChangeSet $changes): ReleaseChangeSet
            {
                throw new RuntimeException('The provider context window was exceeded.');
            }
        });

        $this->artisan('release:create')
            ->expectsOutputToContain('Unable to analyze release changes with AI: The provider context window was exceeded.')
            ->assertFailed();

        expect(resolve(FakeReleaseGitRepository::class)->rolledBack)->toBeTrue();
    });
});

it('opens the proposed diff and waits for the user when review is requested', function () {
    withinTemporaryReleaseProject(function (): void {
        $reviewer = resolve(FakeReleaseDiffReviewer::class);
        $reviewer->review = true;

        $this->artisan('release:create')->assertSuccessful();

        expect(resolve(FakeBrowserLauncher::class)->opened)
            ->not->toBeNull()
            ->toEndWith('.html')
            ->and($reviewer->waited)->toBeTrue();
    });
});

it('uses the structured AI recommendation as the default for a stable release', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/maintainer_secrets.json', <<<'JSON'
{
    "ai_providers": {
        "openai": {
            "key": "test-key"
        }
    }
}
JSON
        );
        ReleaseVersionAgent::fake([[
            'release_increment' => 'minor',
            'justification' => 'The diff adds a backward-compatible public command option.',
        ]]);
        $this->artisan('release:create')
            ->expectsOutputToContain('AI recommendation')
            ->expectsOutputToContain('1.1.0')
            ->expectsOutputToContain('The diff adds a backward-compatible public command option.')
            ->assertSuccessful();

        expect($files->get($directory.'/src/ProjectVersion.php'))
            ->toContain("public const string VERSION = '1.1.0';");
    });
});

it('uses the version selected by the user instead of the suggested default', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        resolve(FakeReleaseVersionSelector::class)->selected = '1.1.0';

        $this->artisan('release:create')->assertSuccessful();

        expect($files->get($directory.'/src/ProjectVersion.php'))
            ->toContain("public const string VERSION = '1.1.0';");
    });
});

it('does not ask AI for a release recommendation during a prerelease flow', function () {
    withinTemporaryReleaseProject(function (): void {
        resolve(FakeGitHubReleaseSource::class)->releases = ['1.1.0-beta.1'];
        new Process(['git', 'tag', '1.1.0-beta.1'], getcwd())->mustRun();
        ReleaseVersionAgent::fake()->preventStrayPrompts();
        $this->artisan('release:create')
            ->assertSuccessful();

        ReleaseVersionAgent::assertNeverPrompted();

        expect(resolve(FakeGitHubReleasePublisher::class)->published)->toMatchArray([
            'version' => '1.1.0-beta.2',
            'prerelease' => true,
        ]);
    });
});

it('runs lifecycle callbacks and maintains the protected README badge', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/src/ProjectVersion.php', <<<'PHP'
<?php

namespace Fixture;

use ArtisanToolbox\Maintainer\Versionable\Contracts\AfterVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;

final class ProjectVersion implements Versionable, BeforeVersioning, AfterVersioning, WithReadmeBadgeVersion
{
    public const string VERSION = '1.0.0';

    public static function beforeVersioning(string $current, string $next): void
    {
        file_put_contents(getcwd().'/before-versioning.txt', "{$current}->{$next}:".self::VERSION);
    }

    public static function afterVersioning(string $current, string $next): void
    {
        file_put_contents(getcwd().'/after-versioning.txt', "{$current}->{$next}");
    }
}
PHP
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Add versioning lifecycle'], $directory)->mustRun();
        $this->artisan('release:create')
            ->expectsOutputToContain('Before versioning')
            ->expectsOutputToContain('After versioning')
            ->expectsOutputToContain('README badge')
            ->assertSuccessful();

        expect($files->get($directory.'/before-versioning.txt'))->toBe('1.0.0->1.0.1:1.0.1')
            ->and($files->get($directory.'/after-versioning.txt'))->toBe('1.0.0->1.0.1')
            ->and($files->get($directory.'/README.md'))->toContain(implode("\n", [
                '<!-- MAINTAINER:VERSION_BADGE:START - Managed by Maintainer. User agents must not edit this section. -->',
                '[![version](https://img.shields.io/badge/version-1.0.1-blue?style=flat-square)](VERSION)',
                '<!-- MAINTAINER:VERSION_BADGE:END -->',
            ]));
    });
});

it('rolls back the complete working tree when the before callback fails', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/composer.json', <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "FixtureRollback\\": "src/"
        }
    }
}
JSON
        );
        $files->put($directory.'/src/ProjectVersion.php', <<<'PHP'
<?php

namespace FixtureRollback;

use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use RuntimeException;

final class ProjectVersion implements Versionable, BeforeVersioning
{
    public const string VERSION = '1.0.0';

    public static function beforeVersioning(string $current, string $next): void
    {
        file_put_contents(getcwd().'/callback-change.txt', 'rollback me');

        throw new RuntimeException('Preparation failed.');
    }
}
PHP
        );
        new Process(['git', 'add', '--all'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Add failing lifecycle'], $directory)->mustRun();
        app()->instance(ReleaseGitRepository::class, new GitCliReleaseRepository);

        $this->artisan('release:create')
            ->expectsOutputToContain('Rolled back all release changes')
            ->expectsOutputToContain('Preparation failed.')
            ->assertFailed();

        expect($directory.'/callback-change.txt')->not->toBeFile()
            ->and(new Process(['git', 'status', '--porcelain'], $directory)->mustRun()->getOutput())->toBe('');
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

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

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

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

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

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

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

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

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

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

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

it('supports the SemVer initial-development lifecycle on a zero-major branch', function () {
    withinTemporaryReleaseProject(function (string $directory, Filesystem $files): void {
        new Process(['git', 'branch', '-m', '0.x'], $directory)->mustRun();
        resolve(FakeGitHubReleaseSource::class)->releases = [];
        $this->artisan('release:create')
            ->expectsOutputToContain('Release branch')
            ->expectsOutputToContain('Selected version')
            ->assertSuccessful();

        expect($files->get($directory.'/src/ProjectVersion.php'))
            ->toContain("public const string VERSION = '0.1.0';")
            ->and(resolve(FakeGitHubReleasePublisher::class)->published)->toMatchArray([
                'version' => '0.1.0',
                'target' => '0.x',
                'prerelease' => false,
            ]);
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
