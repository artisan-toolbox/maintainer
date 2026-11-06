<?php

namespace App\Commands\Versioning;

use App\Support\Git\GitPushRepository;
use App\Support\Git\PendingGitPush;
use App\Support\ProjectPath;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use LogicException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

#[Signature('push {--branch= : Local branch to push} {--yes : Confirm the reviewed push in non-interactive mode}')]
#[Description('Review and push pending Git commits to origin')]
final class PushCommitsCommand extends Command
{
    public function handle(ProjectPath $projectPath, GitPushRepository $repository): int
    {
        $branch = $this->option('branch');

        if (! $this->input->isInteractive() && (! is_string($branch) || $branch === '' || ! $this->option('yes'))) {
            $this->components->error('Non-interactive pushes require --branch=<local-branch> and --yes.');

            return self::FAILURE;
        }

        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            $pushes = $this->working(
                fn (): array => $repository->pending($projectRoot, is_string($branch) ? $branch : null),
                'Checking origin for pending commits...',
            );

            if ($pushes === []) {
                $this->components->info('There are no pending commits to push.');

                return self::SUCCESS;
            }

            $options = [];
            $available = [];

            foreach ($pushes as $index => $push) {
                $key = "branch-{$index}";
                $available[$key] = $push;
                $options[$key] = "{$push->branch} → origin/".substr($push->targetRef, strlen('refs/heads/'))
                    .' · '.count($push->commits).' pending commit(s)'.($push->problem === null ? '' : ' · blocked');
            }

            $selected = is_string($branch) ? array_key_first($options) : select(
                label: 'Which branch would you like to push?',
                options: $options,
                hint: 'A push includes all pending commits in the selected branch history.',
            );
            $push = $available[$selected] ?? throw new LogicException('The selected branch is not available for review.');
            $this->review($push);

            if ($push->problem !== null) {
                $this->components->error($push->problem);

                return self::FAILURE;
            }

            if ($push->commits === []) {
                $this->components->info('There are no pending commits to push.');

                return self::SUCCESS;
            }

            if ($this->input->isInteractive() && ! confirm('Push all reviewed commits to this origin branch?', false)) {
                $this->components->info('Push cancelled.');

                return self::SUCCESS;
            }

            $this->working(function () use ($repository, $projectRoot, $push): void {
                $repository->push($projectRoot, $push);
            }, 'Rechecking origin and pushing the reviewed commits...');
            $this->components->success('Pushed the reviewed commits to origin.');
        } catch (Throwable $exception) {
            $this->components->error("Unable to push Git commits: {$exception->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function review(PendingGitPush $push): void
    {
        $this->components->twoColumnDetail('Local branch', OutputFormatter::escape($push->branch));
        $this->components->twoColumnDetail('Destination', OutputFormatter::escape('origin/'.substr($push->targetRef, strlen('refs/heads/'))));
        $this->components->twoColumnDetail('Remote branch', $push->remoteTip === null ? 'New branch' : 'Existing branch');
        $this->components->twoColumnDetail('Pending commits', (string) count($push->commits));

        if ($push->commits !== []) {
            $this->components->bulletList(array_map(OutputFormatter::escape(...), $push->commits));
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function working(Closure $callback, string $message): mixed
    {
        if ($this->input->isInteractive()) {
            return spin($callback, $message);
        }

        $this->components->info($message);

        return $callback();
    }
}
