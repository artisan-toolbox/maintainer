<?php

namespace App\Support\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

final class GitWorkingTree
{
    /**
     * Determine whether the Git working tree has no staged, unstaged, or untracked changes.
     */
    public function isClean(string $projectRoot): bool
    {
        $process = new Process([
            'git',
            'status',
            '--porcelain=v1',
            '--untracked-files=all',
        ], $projectRoot);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'Git could not inspect the working tree.';

            throw new RuntimeException($message);
        }

        return $process->getOutput() === '';
    }
}
