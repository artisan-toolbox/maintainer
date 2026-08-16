<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class GitDiffGenerator
{
    /**
     * Generate a Git diff between references or against the working tree.
     */
    public function generate(string $projectRoot, string $base, ?string $target = null): string
    {
        $this->ensureValidReference($base);

        if ($target !== null) {
            $this->ensureValidReference($target);
        }

        $command = [
            'git',
            'diff',
            '--no-ext-diff',
            '--no-color',
            '--find-renames',
            $base,
        ];

        if ($target !== null) {
            $command[] = $target;
        }

        $command[] = '--';
        $command[] = '.';

        $process = new Process($command, $projectRoot);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'Git could not generate the requested diff.';

            throw new RuntimeException($message);
        }

        return $process->getOutput();
    }

    private function ensureValidReference(string $reference): void
    {
        throw_if($reference === '' || str_starts_with($reference, '-'), RuntimeException::class, "Invalid Git reference: {$reference}");
    }
}
