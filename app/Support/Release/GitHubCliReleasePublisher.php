<?php

namespace App\Support\Release;

use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
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
        ?string $verifiedTagRepository = null,
    ): string {
        $command = ['gh', 'release', 'create', $version, '--target', $target, '--title', $title];
        $command = $verifiedTagRepository === null
            ? [...$command, '--notes', $body]
            : [...$command, '--repo', $verifiedTagRepository, '--verify-tag', '--notes-file', '-'];

        if ($prerelease) {
            $command[] = '--prerelease';
        }

        $process = $this->process($command, $projectRoot);
        if ($verifiedTagRepository !== null) {
            $process->setInput($body);
        }
        $process->setTimeout(120);

        try {
            $process->run();
            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
            $message = trim($process->getErrorOutput()) ?: 'GitHub CLI could not publish the release.';
        } catch (ProcessTimedOutException $exception) {
            throw_if($verifiedTagRepository === null, $exception);
            $message = $exception->getMessage();
        }

        if ($verifiedTagRepository !== null) {
            $published = $this->publishedReleaseUrl($projectRoot, $verifiedTagRepository, $version, $title, $body, $prerelease);
            if ($published !== null) {
                return $published;
            }
            $message .= " A matching published release could not be confirmed for {$version}. Inspect the release on GitHub before retrying.";
        }

        throw new RuntimeException($message);
    }

    private function publishedReleaseUrl(
        string $projectRoot,
        string $repository,
        string $version,
        string $title,
        string $body,
        bool $prerelease,
    ): ?string {
        $process = $this->process([
            'gh', 'release', 'view', $version, '--repo', $repository,
            '--json', 'url,tagName,name,body,isDraft,isPrerelease',
        ], $projectRoot);
        $process->setTimeout(30);

        try {
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $release = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|ProcessTimedOutException) {
            return null;
        }

        if (
            ! is_array($release)
            || ($release['tagName'] ?? null) !== $version
            || ($release['name'] ?? null) !== $title
            || ($release['isDraft'] ?? null) !== false
            || ($release['isPrerelease'] ?? null) !== $prerelease
            || ! is_string($release['body'] ?? null)
            || rtrim(str_replace("\r\n", "\n", $release['body']), "\n") !== rtrim(str_replace("\r\n", "\n", $body), "\n")
            || ! is_string($release['url'] ?? null)
            || $release['url'] === ''
        ) {
            return null;
        }

        return $release['url'];
    }

    /** @param list<string> $command */
    private function process(array $command, string $projectRoot): Process
    {
        return $this->processFactory === null
            ? new Process($command, $projectRoot)
            : ($this->processFactory)($command, $projectRoot);
    }
}
