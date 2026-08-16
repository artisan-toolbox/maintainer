<?php

namespace App\Support\Release;

use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class GitHubCliReleaseSource implements GitHubReleaseSource
{
    /**
     * @param  (Closure(list<string>, string): Process)|null  $processFactory
     */
    public function __construct(private ?Closure $processFactory = null) {}

    /**
     * @return list<string>
     */
    public function versions(string $projectRoot): array
    {
        $command = [
            'gh',
            'api',
            'repos/{owner}/{repo}/releases?per_page=100',
            '--paginate',
            '--slurp',
        ];
        $process = $this->processFactory === null
            ? new Process($command, $projectRoot)
            : ($this->processFactory)($command, $projectRoot);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'GitHub CLI could not list the repository releases.';

            throw new RuntimeException($message);
        }

        try {
            $pages = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GitHub CLI returned invalid release data.', previous: $exception);
        }

        throw_unless(is_array($pages), RuntimeException::class, 'GitHub CLI returned an unexpected release response.');

        $versions = [];

        foreach ($pages as $releases) {
            if (! is_array($releases)) {
                continue;
            }

            foreach ($releases as $release) {
                if (! is_array($release) || ($release['draft'] ?? false) === true) {
                    continue;
                }

                $tag = $release['tag_name'] ?? null;

                if (is_string($tag)) {
                    $versions[] = $tag;
                }
            }
        }

        return $versions;
    }
}
