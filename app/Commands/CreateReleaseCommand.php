<?php

namespace App\Commands;

use App\Support\Ai\ConfiguredAiProvider;
use App\Support\Ai\ReleaseChangelogGenerator;
use App\Support\Ai\ReleaseIncrement;
use App\Support\Ai\ReleaseNotesGenerator;
use App\Support\Ai\ReleaseVersionRecommender;
use App\Support\Git\GitWorkingTree;
use App\Support\Git\ReleaseBranch;
use App\Support\ProjectPath;
use App\Support\Release\ChangelogWriter;
use App\Support\Release\GitHubReleasePublisher;
use App\Support\Release\LatestGitHubRelease;
use App\Support\Release\ReadmeVersionBadge;
use App\Support\Release\ReleaseGitRepository;
use App\Support\Release\ReleaseVersionOptions;
use App\Support\Release\SemanticVersion;
use App\Support\Release\VersionableClass;
use App\Support\Release\VersionableImplementation;
use App\Support\Release\VersionableVersionWriter;
use App\Support\Release\VersioningLifecycle;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

#[Signature('release:create')]
#[Description('Create a new GitHub release for the project')]
final class CreateReleaseCommand extends Command
{
    /**
     * Execute the complete GitHub release workflow.
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
        ConfiguredAiProvider $configuredAiProvider,
        ReleaseVersionRecommender $releaseVersionRecommender,
        VersioningLifecycle $lifecycle,
        ReadmeVersionBadge $readmeBadge,
        ReleaseGitRepository $git,
        ReleaseNotesGenerator $releaseNotesGenerator,
        ReleaseChangelogGenerator $changelogGenerator,
        ChangelogWriter $changelogWriter,
        GitHubReleasePublisher $publisher,
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

            $major = $releaseBranch->major($projectRoot);
            $versionableClass = $this->validatedVersionable($versionableImplementation, $semanticVersion, $projectRoot);
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to complete the initial release validation: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('The release:create command requires interactive input to select the next GitHub release version and review the release.');

            return self::FAILURE;
        }

        try {
            $baseline = $git->head($projectRoot);
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to capture the release rollback point: '.$exception->getMessage());

            return self::FAILURE;
        }

        $pushed = false;

        try {
            if ($lifecycle->before($versionableClass)) {
                $this->components->twoColumnDetail('Before versioning', 'Completed');
            }

            $latestVersion = spin(
                fn () => $latestGitHubRelease->forMajor($projectRoot, $major),
                'Fetching GitHub releases...',
            );
            $options = $releaseVersionOptions->forMajor($major, $latestVersion);

            $this->components->twoColumnDetail('Release branch', "{$major}.x");
            $this->components->twoColumnDetail('Latest GitHub version', $latestVersion?->value() ?? 'No valid release found');

            $defaultVersion = (string) array_key_first($options);

            if ($latestVersion !== null && $latestVersion->prerelease === null) {
                try {
                    $provider = $configuredAiProvider->for('release_type_suggestion');
                    $recommendation = spin(
                        fn () => $releaseVersionRecommender->recommend($provider, $projectRoot, $latestVersion),
                        "Analyzing the release diff with {$provider}...",
                    );
                    $defaultVersion = $recommendation->increment === ReleaseIncrement::Minor
                        ? "{$major}.".($latestVersion->minor + 1).'.0'
                        : "{$major}.{$latestVersion->minor}.".($latestVersion->patch + 1);

                    $this->components->twoColumnDetail('AI recommendation', ucfirst($recommendation->increment->value)." — {$defaultVersion}");
                    $this->components->twoColumnDetail('Reason', $recommendation->justification);
                } catch (Throwable $exception) {
                    $this->components->warn('Unable to analyze the release diff with AI. Patch remains the default: '.$exception->getMessage());
                }
            }

            $selectedVersion = (string) select(
                label: 'Which version should be released?',
                options: $options,
                default: $defaultVersion,
            );
            $this->components->twoColumnDetail('Selected version', $selectedVersion);

            $changes = $git->changesSince($projectRoot, $latestVersion?->value());
            $notesProvider = $configuredAiProvider->for('release_notes');
            $releaseNotes = spin(
                fn () => $releaseNotesGenerator->generate($notesProvider, $selectedVersion, $changes),
                "Writing GitHub release notes with {$notesProvider}...",
            );
            $changelogProvider = $configuredAiProvider->for('release_changelog_update');
            $changelogEntries = spin(
                fn () => $changelogGenerator->generate($changelogProvider, $selectedVersion, $changes),
                "Building the changelog with {$changelogProvider}...",
            );

            $versionWriter->write($versionableClass, $selectedVersion);
            $this->components->twoColumnDetail('Version file', $versionableClass->file);

            if ($readmeBadge->update($projectRoot, $versionableClass, $selectedVersion)) {
                $this->components->twoColumnDetail('README badge', $selectedVersion);
            }

            $changelogPath = $changelogWriter->write($projectRoot, $selectedVersion, $changelogEntries);
            $this->components->twoColumnDetail('Changelog', $changelogPath);

            $git->stageAll($projectRoot);

            if ($latestVersion !== null && confirm(
                'Would you like to review the proposed release diff in your browser before continuing?',
                true,
            )) {
                throw_if($this->call('diff:html', ['base' => $latestVersion->value()]) !== self::SUCCESS, RuntimeException::class, 'The proposed release diff could not be opened.');

                pause('Return to this terminal and press enter to continue the release...');
            }

            $commit = spin(
                fn (): string => $git->commit($projectRoot, $selectedVersion),
                'Committing the release files...',
            );
            $this->components->twoColumnDetail('Commit', $commit);

            spin(
                fn () => $git->push($projectRoot),
                'Pushing the release commit to origin...',
            );
            $pushed = true;
            $this->components->success('Pushed the release commit to origin.');

            $selected = $semanticVersion->parse($selectedVersion);
            $releaseUrl = spin(
                fn (): string => $publisher->publish(
                    $projectRoot,
                    $selectedVersion,
                    "{$major}.x",
                    $releaseNotes->title,
                    $releaseNotes->body,
                    $selected?->prerelease !== null,
                ),
                'Publishing the GitHub release...',
            );
            $this->components->twoColumnDetail('GitHub release', $releaseUrl);

            if ($lifecycle->after($versionableClass)) {
                $this->components->twoColumnDetail('After versioning', 'Completed');
            }

            $this->components->success("Published GitHub release {$selectedVersion}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (! $pushed) {
                try {
                    $git->rollback($projectRoot, $baseline);
                    $this->components->warn('Rolled back all release changes in the Git working tree.');
                } catch (Throwable $rollbackException) {
                    $this->components->error('The release failed and the Git working tree could not be rolled back: '.$rollbackException->getMessage());
                }
            }

            $this->components->error('Unable to create the GitHub release: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function validatedVersionable(
        VersionableImplementation $implementation,
        SemanticVersion $semanticVersion,
        string $projectRoot,
    ): VersionableClass {
        $versionable = $implementation->find($projectRoot);

        if ($versionable === null) {
            throw new RuntimeException(sprintf(
                'No class directly in a production PSR-4 namespace implements %s.',
                Versionable::class,
            ));
        }

        if ($versionable->hasVersionConstant && $versionable->version === null) {
            throw new RuntimeException("{$versionable->name} must declare public const string VERSION.");
        }

        if ($versionable->version !== null && ! $semanticVersion->isValid($versionable->version)) {
            throw new RuntimeException(sprintf(
                '%s::VERSION must use MAJOR.MINOR.PATCH with an optional alpha or beta prerelease. Received: %s',
                $versionable->name,
                $versionable->version,
            ));
        }

        $this->components->twoColumnDetail('Versionable class', $versionable->name);
        $this->components->twoColumnDetail('Current version', $versionable->version ?? 'Not declared');

        return $versionable;
    }
}
