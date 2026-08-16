<?php

namespace App\Commands;

use App\Support\Git\GitWorkingTree;
use App\Support\Git\ReleaseBranch;
use App\Support\ProjectPath;
use App\Support\Release\LatestGitHubRelease;
use App\Support\Release\ReleaseVersionOptions;
use App\Support\Release\SemanticVersion;
use App\Support\Release\VersionableImplementation;
use App\Support\Release\VersionableVersionWriter;
use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

#[Signature('release:create')]
#[Description('Create a new GitHub release for the project')]
final class CreateReleaseCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        ProjectPath $projectPath,
        GitWorkingTree $workingTree,
        VersionableImplementation $versionableImplementation,
        SemanticVersion $semanticVersion,
        ReleaseBranch $releaseBranch,
        LatestGitHubRelease $latestGitHubRelease,
        ReleaseVersionOptions $releaseVersionOptions,
        VersionableVersionWriter $versionWriter,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            if (! $workingTree->isClean($projectRoot)) {
                $this->components->error('The Git working tree is not clean. Commit or discard all staged, unstaged, and untracked changes before creating a GitHub release.');

                return self::FAILURE;
            }
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to verify the Git working tree: '.$exception->getMessage());

            return self::FAILURE;
        }

        try {
            $major = $releaseBranch->major($projectRoot);
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to validate the release branch: '.$exception->getMessage());

            return self::FAILURE;
        }

        try {
            $versionableClass = $versionableImplementation->find($projectRoot);

            if ($versionableClass === null) {
                $this->components->error(sprintf(
                    'No class directly in a production PSR-4 namespace implements %s.',
                    Versionable::class,
                ));

                return self::FAILURE;
            }

            if ($versionableClass->hasVersionConstant && $versionableClass->version === null) {
                $this->components->error(sprintf(
                    '%s must declare public const string VERSION.',
                    $versionableClass->name,
                ));

                return self::FAILURE;
            }

            if ($versionableClass->version !== null && ! $semanticVersion->isValid($versionableClass->version)) {
                $this->components->error(sprintf(
                    '%s::VERSION must use MAJOR.MINOR.PATCH with an optional alpha or beta prerelease. Received: %s',
                    $versionableClass->name,
                    $versionableClass->version,
                ));

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('Versionable class', $versionableClass->name);
            $this->components->twoColumnDetail('Current version', $versionableClass->version ?? 'Not declared');
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to inspect the project version class: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('The release:create command requires interactive input to select the next GitHub release version.');

            return self::FAILURE;
        }

        try {
            $latestVersion = spin(
                fn () => $latestGitHubRelease->forMajor($projectRoot, $major),
                'Fetching GitHub releases...',
            );
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to retrieve GitHub releases: '.$exception->getMessage());

            return self::FAILURE;
        }

        $options = $releaseVersionOptions->forMajor($major, $latestVersion);

        $this->components->twoColumnDetail('Release branch', "{$major}.x");
        $this->components->twoColumnDetail(
            'Latest GitHub version',
            $latestVersion?->value() ?? 'No valid release found',
        );

        $selectedVersion = select(
            label: 'Which version should be released?',
            options: $options,
            default: array_key_first($options),
        );

        $this->components->twoColumnDetail('Selected version', (string) $selectedVersion);

        try {
            $versionWriter->write($versionableClass, (string) $selectedVersion);
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to update the project version: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Version file', $versionableClass->file);

        return self::SUCCESS;
    }
}
