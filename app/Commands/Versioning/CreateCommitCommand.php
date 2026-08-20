<?php

namespace App\Commands\Versioning;

use App\Support\Ai\CommitMessageGenerator;
use App\Support\Ai\ConfiguredAiProvider;
use App\Support\Git\CommitMessageMode;
use App\Support\Git\GitCommitRepository;
use App\Support\Git\GitFileSelector;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\textarea;

#[Signature('commit')]
#[Description('Create a Git commit from selected project changes')]
final class CreateCommitCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        ProjectPath $projectPath,
        GitCommitRepository $repository,
        GitFileSelector $fileSelector,
        ConfiguredAiProvider $configuredAiProvider,
        CommitMessageGenerator $messageGenerator,
    ): int {
        if (! $this->input->isInteractive()) {
            $this->components->error('The commit command requires interactive input to review changes, select files, and create a commit message.');

            return self::FAILURE;
        }

        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            $changes = $repository->changes($projectRoot);

            if ($changes === []) {
                $this->components->warn('There are no changed files to commit.');

                return self::SUCCESS;
            }

            if (confirm('Would you like to review the complete Git diff in your browser before selecting files?', true)) {
                if ($this->call('diff:html') !== self::SUCCESS) {
                    return self::FAILURE;
                }

                pause('Return to this terminal and press enter to continue...');
            }

            $selectedFiles = $fileSelector->select($changes);

            spin(
                function () use ($repository, $projectRoot, $selectedFiles): void {
                    $repository->stageOnly($projectRoot, $selectedFiles);
                },
                'Preparing the selected files...',
            );

            $status = $repository->stagedStatus($projectRoot);
            $diff = $repository->stagedDiff($projectRoot);

            if ($diff === '') {
                $this->components->error('The selected files did not produce a staged diff.');

                return self::FAILURE;
            }

            $mode = CommitMessageMode::from(select(
                label: 'How should the commit message be created?',
                options: [
                    CommitMessageMode::Manual->value => 'Write it manually',
                    CommitMessageMode::Ai->value => 'Generate it with AI',
                    CommitMessageMode::AiWithContext->value => 'Generate it with AI and additional context',
                ],
                default: CommitMessageMode::Ai->value,
            ));

            $message = $this->commitMessage(
                $mode,
                $status,
                $diff,
                $configuredAiProvider,
                $messageGenerator,
            );

            note("Commit message:\n\n{$message}");

            $commitOutput = spin(
                fn (): string => $repository->commit($projectRoot, $message),
                'Creating the Git commit...',
            );

            $this->components->twoColumnDetail('Commit', $this->commitSummary($commitOutput));
            $this->components->success('Created the Git commit.');

            if (confirm('Push this commit to origin?', false)) {
                spin(
                    fn (): string => $repository->pushToOrigin($projectRoot),
                    'Pushing the commit to origin...',
                );

                $this->components->success('Pushed the commit to origin.');
            }
        } catch (Throwable $exception) {
            $this->components->error("Unable to create the Git commit: {$exception->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function commitMessage(
        CommitMessageMode $mode,
        string $status,
        string $diff,
        ConfiguredAiProvider $configuredAiProvider,
        CommitMessageGenerator $messageGenerator,
    ): string {
        if ($mode === CommitMessageMode::Manual) {
            return trim(textarea(
                label: 'Write the commit message',
                placeholder: 'feat(scope): describe the change',
                required: 'A commit message is required.',
                validate: static fn (string $value): ?string => trim($value) === ''
                    ? 'A commit message is required.'
                    : null,
                hint: 'Press Ctrl+D to finish editing.',
                rows: 8,
            ));
        }

        $context = $mode === CommitMessageMode::AiWithContext
            ? textarea(
                label: 'What additional context should the AI consider?',
                placeholder: 'Explain the intent, trade-offs, tests, issue references, or breaking changes.',
                required: 'Context is required for this option.',
                validate: static fn (string $value): ?string => trim($value) === ''
                    ? 'Context is required for this option.'
                    : null,
                hint: 'Press Ctrl+D to finish editing.',
                rows: 8,
            )
            : null;
        $provider = $configuredAiProvider->for('commit_message');

        return spin(
            fn (): string => $messageGenerator->generate($provider, $status, $diff, $context),
            "Generating the commit message with {$provider}...",
        );
    }

    private function commitSummary(string $output): string
    {
        $firstLine = strtok($output, "\n");

        return $firstLine === false ? 'Created' : $firstLine;
    }
}
