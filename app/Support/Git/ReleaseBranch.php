<?php

namespace App\Support\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ReleaseBranch
{
    public function major(string $projectRoot): int
    {
        $process = new Process(['git', 'branch', '--show-current'], $projectRoot);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'Git could not determine the current branch.';

            throw new RuntimeException($message);
        }

        $branch = trim($process->getOutput());

        if (preg_match('/^(?<major>[1-9]\d*)\.x$/', $branch, $matches) !== 1) {
            throw new RuntimeException(
                $branch === ''
                    ? 'A release cannot be created from a detached HEAD. Check out a branch such as 1.x or 2.x.'
                    : "The branch {$branch} is not a release branch. Use a branch such as 1.x or 2.x.",
            );
        }

        return (int) $matches['major'];
    }
}
