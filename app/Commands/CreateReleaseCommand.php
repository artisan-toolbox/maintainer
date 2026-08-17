<?php

namespace App\Commands;

use App\Support\Ai\ChangelogEntry;
use App\Support\Ai\ConfiguredAiProvider;
use App\Support\Ai\ReleaseChangeAnalyzer;
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
use App\Support\Release\ReleaseChangeSet;
use App\Support\Release\ReleaseDiffReviewer;
use App\Support\Release\ReleaseGitRepository;
use App\Support\Release\ReleaseVersionOptions;
use App\Support\Release\ReleaseVersionSelector;
use App\Support\Release\ReleaseWorktreeRollback;
use App\Support\Release\SemanticVersion;
use App\Support\Release\VersionableClass;
use App\Support\Release\VersionableImplementation;
use App\Support\Release\VersionableVersionWriter;
use App\Support\Release\VersioningLifecycle;
use ArtisanToolbox\Maintainer\Versionable\Contracts\AfterVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

use function Laravel\Prompts\spin;

#[Signature('release:create')]
#[Description('Create a new GitHub release for the project')]
final class CreateReleaseCommand extends Command implements SignalableCommandInterface
{
    private const int SIGTERM = 15;

    private ?ReleaseWorktreeRollback $releaseRollback = null;

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
        ReleaseChangeAnalyzer $releaseChangeAnalyzer,
        ReleaseNotesGenerator $releaseNotesGenerator,
        ReleaseChangelogGenerator $changelogGenerator,
        ChangelogWriter $changelogWriter,
        GitHubReleasePublisher $publisher,
        ReleaseDiffReviewer $diffReviewer,
        ReleaseVersionSelector $versionSelector,
        ReleaseWorktreeRollback $releaseRollback,
    ): int {
        $this->releaseRollback = $releaseRollback;
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
            $releaseRollback->arm($git, $projectRoot, $baseline);
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to capture the release rollback point: '.$exception->getMessage());

            return self::FAILURE;
        }

        $operation = 'complete the release workflow';

        try {
            $operation = 'fetch GitHub releases';
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

            $operation = 'select the next release version';
            $selectedVersion = $versionSelector->select($options, $defaultVersion);
            $this->components->twoColumnDetail('Selected version', $selectedVersion);
            $currentVersion = $versionableClass->version
                ?? $latestVersion?->value()
                ?? "{$major}.0.0";
            $operation = 'write the selected project version';
            $versionWriter->write($versionableClass, $selectedVersion);
            $this->components->twoColumnDetail('Version file', $versionableClass->file);

            if ($versionableClass->implements(BeforeVersioning::class)) {
                $operation = 'run the before-versioning callback';
                spin(
                    fn (): bool => $lifecycle->before($versionableClass, $currentVersion, $selectedVersion),
                    "Running the before-versioning callback for {$selectedVersion}...",
                );
                $this->components->twoColumnDetail('Before versioning', "{$currentVersion} → {$selectedVersion}");
            }

            $operation = 'collect release changes';
            $changes = $git->changesSince($projectRoot, $latestVersion?->value());
            $changelogProvider = $configuredAiProvider->for('release_changelog_update');
            $operation = 'analyze release changes with AI';
            $analyzedChanges = spin(
                fn () => $releaseChangeAnalyzer->analyze($changelogProvider, $changes),
                "Summarizing bounded release diff fragments with {$changelogProvider}...",
            );
            $operation = 'generate the release changelog';
            $changelogEntries = spin(
                fn () => $changelogGenerator->generate($changelogProvider, $selectedVersion, $analyzedChanges),
                "Building the changelog with {$changelogProvider}...",
            );
            $notesProvider = $configuredAiProvider->for('release_notes');
            $operation = 'generate GitHub release notes';
            $releaseNotes = spin(
                fn () => $releaseNotesGenerator->generate(
                    $notesProvider,
                    $selectedVersion,
                    $this->releaseNotesContext($analyzedChanges, $changelogEntries),
                ),
                "Writing GitHub release notes with {$notesProvider}...",
            );

            $operation = 'update the README version badge';
            if ($readmeBadge->update($projectRoot, $versionableClass, $selectedVersion)) {
                $this->components->twoColumnDetail('README badge', $selectedVersion);
            }

            $operation = 'write CHANGELOG.md';
            $changelogPath = $changelogWriter->write($projectRoot, $selectedVersion, $changelogEntries);
            $this->components->twoColumnDetail('Changelog', $changelogPath);

            $operation = 'stage the release files';
            $git->stageAll($projectRoot);

            if ($latestVersion !== null && $diffReviewer->shouldReview()) {
                $operation = 'open the proposed release diff';
                throw_if($this->call('diff:html', ['base' => $latestVersion->value()]) !== self::SUCCESS, RuntimeException::class, 'The proposed release diff could not be opened.');

                $diffReviewer->waitForReturn();
            }

            $operation = 'create the release commit';
            $commit = spin(
                fn (): string => $git->commit($projectRoot, $selectedVersion),
                'Committing the release files...',
            );
            $this->components->twoColumnDetail('Commit', $commit);

            $operation = 'push the release commit';
            spin(
                function () use ($git, $projectRoot, $releaseRollback): void {
                    $git->push($projectRoot);
                    $releaseRollback->markPushed();
                },
                'Pushing the release commit to origin...',
            );
            $this->components->success('Pushed the release commit to origin.');

            $selected = $semanticVersion->parse($selectedVersion);
            $operation = 'publish the GitHub release';
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

            if ($versionableClass->implements(AfterVersioning::class)) {
                $operation = 'run the after-versioning callback';
                spin(
                    fn (): bool => $lifecycle->after($versionableClass, $currentVersion, $selectedVersion),
                    "Running the after-versioning callback for {$selectedVersion}...",
                );
                $this->components->twoColumnDetail('After versioning', "{$currentVersion} → {$selectedVersion}");
            }

            $releaseRollback->disarm();
            $this->components->success("Published GitHub release {$selectedVersion}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (! $releaseRollback->wasPushed()) {
                try {
                    if ($releaseRollback->rollback()) {
                        $this->components->warn('Rolled back all release changes in the Git working tree.');
                    }
                } catch (Throwable $rollbackException) {
                    $this->components->error('The release failed and the Git working tree could not be rolled back: '.$rollbackException->getMessage());
                }
            }

            $this->components->error("Unable to {$operation}: ".$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        if (! defined('SIGTERM') || ! function_exists('pcntl_signal')) {
            return [];
        }

        return [self::SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($signal !== self::SIGTERM) {
            return false;
        }

        if ($this->releaseRollback === null || ! $this->releaseRollback->isArmed()) {
            $this->components->warn('SIGTERM received before release changes were prepared. No rollback was required.');

            return 128 + self::SIGTERM;
        }

        if ($this->releaseRollback->wasPushed()) {
            $this->components->warn('SIGTERM received after the release commit was pushed. Automatic rollback was skipped because remote changes cannot be safely reverted.');

            return 128 + self::SIGTERM;
        }

        try {
            if ($this->releaseRollback->rollback()) {
                $this->components->warn('SIGTERM received. Rolled back all release changes in the Git working tree.');
            }
        } catch (Throwable $exception) {
            $this->components->error('SIGTERM received, but the Git working tree could not be rolled back: '.$exception->getMessage());
        }

        return 128 + self::SIGTERM;
    }

    /**
     * @param  list<ChangelogEntry>  $entries
     */
    private function releaseNotesContext(ReleaseChangeSet $changes, array $entries): ReleaseChangeSet
    {
        $summary = collect($entries)
            ->map(fn (ChangelogEntry $entry): string => sprintf(
                '[%s] %s %s%s%s',
                $entry->type,
                $entry->hash,
                $entry->title,
                PHP_EOL,
                $entry->description,
            ))
            ->implode(PHP_EOL.PHP_EOL);

        return new ReleaseChangeSet(
            Str::limit($summary, 40_000, PHP_EOL.'… additional changelog entries omitted'),
            $changes->commits,
        );
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
