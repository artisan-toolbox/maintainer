<?php

namespace App\Support\Release;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class GitHubCliReleasePublisher implements GitHubReleasePublisher
{
    /**
     * @param  (Closure(list<string>, string): Process)|null  $processFactory
     */
    public function __construct(private ?Closure $processFactory = null) {}

    public function publish(
        string $projectRoot,
        string $version,
        string $target,
        string $title,
        string $body,
        bool $prerelease,
    ): string {
        $command = ['gh', 'release', 'create', $version, '--target', $target, '--title', $title, '--notes', $body];

        if ($prerelease) {
            $command[] = '--prerelease';
        }

        $process = $this->processFactory === null
            ? new Process($command, $projectRoot)
            : ($this->processFactory)($command, $projectRoot);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'GitHub CLI could not publish the release.');
        }

        return trim($process->getOutput());
    }
}
