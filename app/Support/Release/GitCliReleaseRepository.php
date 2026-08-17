<?php

namespace App\Support\Release;

use RuntimeException;
use Symfony\Component\Process\Process;

final class GitCliReleaseRepository implements ReleaseGitRepository
{
    public function head(string $projectRoot): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD'], $projectRoot));
    }

    public function ensureLocalReference(string $projectRoot, string $reference): void
    {
        if ($this->hasCommit($projectRoot, $reference)) {
            return;
        }

        try {
            $this->run(['git', 'fetch', '--no-tags', 'origin', 'tag', $reference], $projectRoot);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                "GitHub release tag {$reference} is not available locally and could not be fetched from origin: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        throw_unless(
            $this->hasCommit($projectRoot, $reference),
            RuntimeException::class,
            "GitHub release tag {$reference} was fetched but does not resolve to a Git commit.",
        );
    }

    public function changesSince(string $projectRoot, ?string $base): ReleaseChangeSet
    {
        $base ??= $this->emptyTree($projectRoot);

        return new ReleaseChangeSet(
            $this->run(['git', 'diff', '--no-ext-diff', '--no-color', '--find-renames', $base, '--', '.'], $projectRoot),
            trim($this->run(['git', 'log', '--format=%h%x09%s', "{$base}..HEAD"], $projectRoot)),
        );
    }

    public function stageAll(string $projectRoot): void
    {
        $this->run(['git', 'add', '--all', '--', '.'], $projectRoot);
    }

    public function commit(string $projectRoot, string $version): string
    {
        return trim($this->run(['git', 'commit', '--message', "chore(release): prepare {$version}"], $projectRoot));
    }

    public function push(string $projectRoot): void
    {
        $this->run(['git', 'push', 'origin', 'HEAD'], $projectRoot);
    }

    public function rollback(string $projectRoot, string $reference): void
    {
        $this->run(['git', 'reset', '--hard', $reference], $projectRoot);
        $this->run(['git', 'clean', '-fd'], $projectRoot);
    }

    private function emptyTree(string $projectRoot): string
    {
        $process = new Process(['git', 'hash-object', '-t', 'tree', '--stdin'], $projectRoot, input: '');
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Git could not create the empty tree reference.');
        }

        return trim($process->getOutput());
    }

    private function hasCommit(string $projectRoot, string $reference): bool
    {
        $process = new Process(['git', 'rev-parse', '--verify', '--quiet', "{$reference}^{commit}"], $projectRoot);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $projectRoot): string
    {
        $process = new Process($command, $projectRoot);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($message !== '' ? $message : 'Git could not complete the release operation.');
        }

        return $process->getOutput();
    }
}
