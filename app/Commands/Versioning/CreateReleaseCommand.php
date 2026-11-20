<?php

namespace App\Commands\Versioning;

use App\Support\Ai\AiConsoleProgress;
use App\Support\Ai\ChangelogEntry;
use App\Support\Ai\ConfiguredAiProvider;
use App\Support\Ai\ReleaseChangeAnalyzer;
use App\Support\Ai\ReleaseChangelogGenerator;
use App\Support\Ai\ReleaseNotesGenerator;
use App\Support\Ai\ReleaseVersionRecommendation;
use App\Support\Ai\ReleaseVersionRecommender;
use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Git\GitWorkingTree;
use App\Support\Git\ReleaseBranch;
use App\Support\ProjectPath;
use App\Support\Quality\QualityCheckPrompt;
use App\Support\Release\ChangelogWriter;
use App\Support\Release\GitHubReleaseGate;
use App\Support\Release\GitHubReleasePublisher;
use App\Support\Release\GitHubReleaseWorkflow;
use App\Support\Release\GitHubWorkflowProgress;
use App\Support\Release\LatestGitHubRelease;
use App\Support\Release\PackageJsonVersionWriter;
use App\Support\Release\PreparedRelease;
use App\Support\Release\PreparedReleaseStore;
use App\Support\Release\ReadmeHeadWriter;
use App\Support\Release\ReleaseChangeSet;
use App\Support\Release\ReleaseDiffReviewer;
use App\Support\Release\ReleaseGitRepository;
use App\Support\Release\ReleaseTitleReviewer;
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
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

use function Illuminate\Filesystem\join_paths;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

#[Signature('release:create {--resume : Retry publication for the prepared release commit}')]
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
        PackageJsonVersionWriter $packageVersionWriter,
        ConfiguredAiProvider $configuredAiProvider,
        AiConsoleProgress $aiProgress,
        ReleaseVersionRecommender $releaseVersionRecommender,
        VersioningLifecycle $lifecycle,
        ReadmeHeadWriter $readmeHeadWriter,
        ReleaseGitRepository $git,
        ReleaseChangeAnalyzer $releaseChangeAnalyzer,
        ReleaseNotesGenerator $releaseNotesGenerator,
        ReleaseChangelogGenerator $changelogGenerator,
        ChangelogWriter $changelogWriter,
        GitHubReleasePublisher $publisher,
        ReleaseDiffReviewer $diffReviewer,
        ReleaseVersionSelector $versionSelector,
        ReleaseTitleReviewer $titleReviewer,
        ReleaseWorktreeRollback $releaseRollback,
        QualityCheckPrompt $qualityCheckPrompt,
        MaintainerConfiguration $configuration,
        GitHubReleaseGate $releaseGate,
        PreparedReleaseStore $preparedReleases,
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
            $versionableClass = $versionableImplementation->findValidated($projectRoot, $semanticVersion);
            $this->components->twoColumnDetail('Versionable class', $versionableClass->name);
            $this->components->twoColumnDetail('Current version', $versionableClass->version ?? 'Not declared');

            $workflowSelectors = GitHubReleaseWorkflow::configuredSelectors($configuration->get('release.workflow'));
            $branch = "{$major}.x";
            $prepared = $preparedReleases->read($projectRoot);

            if ($prepared !== null && $prepared->commit !== $git->head($projectRoot)) {
                $prepared = null;
            }

            if ($this->option('resume') || $prepared !== null) {
                throw_unless(
                    $prepared !== null
                        && $workflowSelectors !== []
                        && $prepared->branch === $branch
                        && $prepared->version === $versionableClass->version,
                    RuntimeException::class,
                    'No prepared release matches HEAD and the current release configuration.',
                );
            }

            if ($prepared === null && ! $this->input->isInteractive()) {
                $this->components->error('The release:create command requires interactive input to select the next GitHub release version and review the release.');

                return self::FAILURE;
            }

            $repository = $workflowSelectors === [] ? null : $git->githubOrigin($projectRoot);
            $workflows = $workflowSelectors === [] || $repository === null
                ? []
                : $releaseGate->resolveWorkflows($projectRoot, $repository, $workflowSelectors);

            throw_if(
                $prepared !== null && ($prepared->repository !== $repository || ! $prepared->requiresWorkflows($workflows)),
                RuntimeException::class,
                'No prepared release matches HEAD and the current release configuration.',
            );
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to complete the initial release validation: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($prepared !== null) {
            if (! $this->option('resume')) {
                if (! $this->input->isInteractive()) {
                    $this->components->error('A release is already prepared at HEAD. Run release:create --resume to continue.');

                    return self::FAILURE;
                }

                if (! confirm("Resume the prepared GitHub release {$prepared->version}?", default: false)) {
                    $this->components->info('The prepared release remains available.');

                    return self::SUCCESS;
                }
            }

            try {
                if ($workflows !== [] && $prepared->workflows != $workflows) {
                    $prepared = $prepared->withWorkflows($workflows);
                    $preparedReleases->write($projectRoot, $prepared);
                }

                $this->publishPrepared($projectRoot, $prepared, $git, $releaseGate, $publisher);
                $preparedReleases->delete($projectRoot);
                $this->finishRelease($lifecycle, $versionableClass, $prepared->currentVersion, $prepared->version);
            } catch (Throwable $exception) {
                $this->components->error('Unable to resume the release: '.$exception->getMessage());

                return self::FAILURE;
            }

            return $this->offerDeployment($projectRoot, $prepared->version);
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
                fn () => $latestGitHubRelease->forMajor($projectRoot, $major, $repository),
                'Fetching GitHub releases...',
            );

            if ($latestVersion !== null) {
                $operation = 'fetch the latest GitHub release tag';
                spin(
                    fn () => $git->ensureLocalReference($projectRoot, $latestVersion->value()),
                    "Fetching Git tag {$latestVersion->value()} from origin...",
                );
                $this->components->twoColumnDetail('Local release tag', $latestVersion->value());
            }

            $options = $releaseVersionOptions->forMajor($major, $latestVersion);

            $this->components->twoColumnDetail('Release branch', "{$major}.x");
            $this->components->twoColumnDetail('Latest GitHub version', $latestVersion?->value() ?? 'No valid release found');

            $defaultVersion = (string) array_key_first($options);

            if ($latestVersion !== null && $latestVersion->prerelease === null) {
                try {
                    $provider = $configuredAiProvider->for('release_type_suggestion');
                    $recommendation = $aiProgress->run(
                        $this->components,
                        'Analyzing the release diff',
                        fn () => $releaseVersionRecommender->recommend($provider, $projectRoot, $latestVersion, $options),
                    );
                    if ($recommendation === null) {
                        $this->components->info('No reviewable changes in the release diff. Patch remains the default.');
                    } else {
                        $this->showVersionRecommendation($recommendation, $options);
                        $defaultVersion = $recommendation->version ?? $defaultVersion;
                    }
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

            $operation = 'update the package.json version';
            if ($packageVersionWriter->canUpdate($projectRoot)) {
                $syncPackageJsonVersion = $packageVersionWriter->shouldSyncAutomatically($projectRoot);

                if (! $syncPackageJsonVersion) {
                    $this->components->info('To sync automatically, set maintainer.syncVersion to true in package.json. See https://atb.dev.br/docs/maintainer/versioning/releases#release-workflow');
                    $syncPackageJsonVersion = confirm("Update package.json to {$selectedVersion}?", default: false);
                }

                if ($syncPackageJsonVersion) {
                    $packageVersionWriter->write($projectRoot, $selectedVersion);
                    $this->components->twoColumnDetail('package.json version', $selectedVersion);
                }
            }

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
            $analyzedChanges = $aiProgress->run(
                $this->components,
                'Summarizing release changes',
                fn () => $releaseChangeAnalyzer->analyze($changelogProvider, $changes),
            );
            $operation = 'generate the release changelog';
            $changelogEntries = $aiProgress->run(
                $this->components,
                'Building the changelog',
                fn () => $changelogGenerator->generate($changelogProvider, $selectedVersion, $analyzedChanges),
            );
            $notesProvider = $configuredAiProvider->for('release_notes');
            $operation = 'generate GitHub release notes';
            $releaseNotes = $aiProgress->run(
                $this->components,
                'Writing GitHub release notes',
                fn () => $releaseNotesGenerator->generate(
                    $notesProvider,
                    $selectedVersion,
                    $this->releaseNotesContext($analyzedChanges, $changelogEntries),
                ),
            );
            $operation = 'review the GitHub release title';
            $releaseTitle = $titleReviewer->review($selectedVersion, $releaseNotes->title);

            $operation = 'update the README header';
            $readmeHead = $lifecycle->readmeHead($versionableClass);

            if ($readmeHead !== null && $readmeHeadWriter->update($projectRoot, $readmeHead, $selectedVersion)) {
                $this->components->twoColumnDetail('README header', 'Updated');
            }

            $operation = 'write CHANGELOG.md';
            $changelogPath = $changelogWriter->write($projectRoot, $selectedVersion, $changelogEntries);
            $this->components->twoColumnDetail('Changelog', $changelogPath);

            $operation = 'apply automatic code-quality fixes';
            throw_if(
                $this->callNonInteractively('quality:fix') !== self::SUCCESS,
                RuntimeException::class,
                'The configured code-quality fixes did not complete successfully.',
            );

            if ($qualityCheckPrompt->shouldRun()) {
                $operation = 'run code-quality checks';
                throw_if(
                    $this->callNonInteractively('quality:check') !== self::SUCCESS,
                    RuntimeException::class,
                    'The configured code-quality checks did not complete successfully.',
                );
            }

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

            if ($workflows !== []) {
                $operation = 'record the prepared release';
                $prepared = new PreparedRelease(
                    $repository,
                    $workflows,
                    $branch,
                    $git->head($projectRoot),
                    $currentVersion,
                    $selectedVersion,
                    $releaseTitle,
                    $releaseNotes->body,
                );
                $preparedReleases->write($projectRoot, $prepared);
                $releaseRollback->disarm();

                $operation = 'publish the prepared GitHub release';
                try {
                    $this->publishPrepared($projectRoot, $prepared, $git, $releaseGate, $publisher);
                } catch (Throwable $exception) {
                    $this->components->warn('The prepared release commit was kept. Resume from Versioning › GitHub releases or run release:create --resume after all required workflows pass.');

                    throw $exception;
                }

                $preparedReleases->delete($projectRoot);
                $operation = 'run the after-versioning callback';
                $this->finishRelease($lifecycle, $versionableClass, $currentVersion, $selectedVersion);

                return $this->offerDeployment($projectRoot, $selectedVersion);
            }

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
                    $branch,
                    $releaseTitle,
                    $releaseNotes->body,
                    $selected?->prerelease !== null,
                ),
                'Publishing the GitHub release...',
            );
            $this->components->twoColumnDetail('GitHub release', $releaseUrl);

            $operation = 'run the after-versioning callback';
            $this->finishRelease($lifecycle, $versionableClass, $currentVersion, $selectedVersion);
            $releaseRollback->disarm();

            return $this->offerDeployment($projectRoot, $selectedVersion);
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

    /** @param array<string, string> $options */
    private function showVersionRecommendation(ReleaseVersionRecommendation $report, array $options): void
    {
        if ($report->version === null) {
            $this->components->warn('A breaking change requires a new major version. Review the change before proceeding on this branch.');
            $this->components->info('Available release options');
        } else {
            $this->components->twoColumnDetail('AI recommendation', $options[$report->version]);
            $this->output->block($report->reasons[$report->version], prefix: '    ');
            $this->components->info('Other release options');
        }

        foreach ($options as $version => $label) {
            if ($version !== $report->version) {
                $this->output->block($label."\n".$report->reasons[$version], prefix: '    ');
            }
        }

        if ($report->truncated) {
            $this->components->warn('The diff exceeded the analysis limit. Review the remaining changes before choosing a version.');
        }
    }

    private function publishPrepared(
        string $projectRoot,
        PreparedRelease $release,
        ReleaseGitRepository $git,
        GitHubReleaseGate $gate,
        GitHubReleasePublisher $publisher,
    ): void {
        spin(
            fn () => $git->pushReleaseCommit($projectRoot, $release),
            'Pushing the prepared release commit to origin...',
        );
        $this->components->twoColumnDetail('Release commit', $release->commit);

        $this->components->info("Waiting for GitHub Actions on {$release->branch}.");
        $runUrls = [];
        $gate->waitForSuccess(
            $projectRoot,
            $release,
            onProgress: function (GitHubWorkflowProgress $progress) use (&$runUrls): void {
                $status = match ($progress->status) {
                    null => 'Waiting for GitHub to create the push run',
                    'queued' => 'Queued',
                    'in_progress' => 'In progress',
                    'completed' => 'Completed ('.str_replace('_', ' ', (string) $progress->conclusion).')',
                    default => ucfirst(str_replace('_', ' ', $progress->status)),
                };
                $elapsed = sprintf('%d:%02d', intdiv($progress->elapsedSeconds, 60), $progress->elapsedSeconds % 60);
                $attempt = $progress->attempt !== null && $progress->attempt > 1 ? " · attempt {$progress->attempt}" : '';
                $this->components->twoColumnDetail($progress->workflow, "{$status}{$attempt} · {$elapsed} elapsed");

                if ($progress->url !== null && $progress->url !== ($runUrls[$progress->workflow] ?? null)) {
                    $runUrls[$progress->workflow] = $progress->url;
                    $this->components->twoColumnDetail('Workflow run', $progress->url);
                }

                foreach ($progress->activeJobs as $job) {
                    $label = Str::limit(Str::replaceMatches('/[\p{C}]+/u', ' ', $job), 100);
                    $this->components->twoColumnDetail('Running job', $label);
                }
            },
        );
        $summary = count($release->workflows) === 1
            ? "The {$release->workflows[0]->selector} workflow passed for the release commit."
            : 'All required workflows passed for the release commit.';
        $this->components->success($summary);

        $releaseUrl = spin(function () use ($projectRoot, $release, $git, $publisher): string {
            $git->pushReleaseTag($projectRoot, $release);

            return $publisher->publish(
                $projectRoot,
                $release->version,
                $release->commit,
                $release->title,
                $release->body,
                str_contains($release->version, '-'),
                $release->repository,
            );
        }, 'Publishing the verified GitHub release...');
        $this->components->twoColumnDetail('GitHub release', $releaseUrl);
    }

    private function finishRelease(
        VersioningLifecycle $lifecycle,
        VersionableClass $versionable,
        string $currentVersion,
        string $selectedVersion,
    ): void {
        if ($versionable->implements(AfterVersioning::class)) {
            spin(
                fn (): bool => $lifecycle->after($versionable, $currentVersion, $selectedVersion),
                "Running the after-versioning callback for {$selectedVersion}...",
            );
            $this->components->twoColumnDetail('After versioning', "{$currentVersion} → {$selectedVersion}");
        }

        $this->components->success("Published GitHub release {$selectedVersion}.");
    }

    private function offerDeployment(string $projectRoot, string $version): int
    {
        if (! $this->input->isInteractive()) {
            return self::SUCCESS;
        }

        foreach (['deploy.php', 'deploy.maml', 'deploy.yaml', 'deploy.yml'] as $recipe) {
            $path = join_paths($projectRoot, $recipe);

            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            if (! confirm("Deploy release {$version} now?", default: false)) {
                return self::SUCCESS;
            }

            try {
                $exitCode = $this->call('deploy', ['--file' => $recipe, '--tag' => $version]);
            } catch (Throwable $exception) {
                $this->components->error("Unable to deploy release {$version}. The GitHub release is already published. {$exception->getMessage()}");

                return self::FAILURE;
            }

            if ($exitCode !== self::SUCCESS) {
                $this->components->warn("GitHub release {$version} is published. The deployment failed.");
            }

            return $exitCode;
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        if (! defined('SIGTERM') || ! function_exists('pcntl_signal')) {
            return [];
        }

        return [self::SIGTERM];
    }

    private function callNonInteractively(string $command): int
    {
        try {
            return $this->call($command, ['--no-interaction' => true]);
        } finally {
            $this->input->setInteractive(true);
            $this->configurePrompts($this->input);
        }
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($signal !== self::SIGTERM) {
            return false;
        }

        if ($this->releaseRollback === null || ! $this->releaseRollback->isArmed()) {
            $this->components->warn('SIGTERM received with no active release rollback. No rollback was required.');

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
}
