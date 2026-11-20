<?php

namespace App\Support\Release;

use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class PreparedReleaseStore
{
    public function __construct(private Filesystem $files) {}

    public function read(string $projectRoot): ?PreparedRelease
    {
        $path = $this->path($projectRoot);

        if (! $this->files->isFile($path)) {
            return null;
        }

        try {
            $release = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The local prepared release record is invalid.', previous: $exception);
        }

        throw_unless(
            is_array($release)
                && is_string($release['repository'] ?? null)
                && is_string($release['branch'] ?? null)
                && is_string($release['commit'] ?? null)
                && is_string($release['currentVersion'] ?? null)
                && is_string($release['version'] ?? null)
                && is_string($release['title'] ?? null)
                && is_string($release['body'] ?? null),
            RuntimeException::class,
            'The local prepared release record is invalid.',
        );

        return new PreparedRelease(
            $release['repository'],
            $this->readWorkflows($release),
            $release['branch'],
            $release['commit'],
            $release['currentVersion'],
            $release['version'],
            $release['title'],
            $release['body'],
        );
    }

    public function write(string $projectRoot, PreparedRelease $release): void
    {
        $this->files->replace($this->path($projectRoot), json_encode($release, flags: JSON_THROW_ON_ERROR));
    }

    public function delete(string $projectRoot): void
    {
        throw_unless(
            $this->files->delete($this->path($projectRoot)),
            RuntimeException::class,
            'The local prepared release record could not be removed.',
        );
    }

    /**
     * @param  array<array-key, mixed>  $release
     * @return non-empty-list<GitHubReleaseWorkflow>
     */
    private function readWorkflows(array $release): array
    {
        if (! array_key_exists('workflows', $release)) {
            $legacy = $release['workflow'] ?? null;
            throw_unless(
                is_string($legacy) && trim($legacy) !== '',
                RuntimeException::class,
                'The local prepared release record has an invalid workflow.',
            );

            return [new GitHubReleaseWorkflow(trim($legacy))];
        }

        $entries = $release['workflows'];
        throw_unless(
            is_array($entries) && array_is_list($entries) && $entries !== [],
            RuntimeException::class,
            'The local prepared release record has invalid workflows.',
        );

        $workflows = [];
        $ids = [];

        foreach ($entries as $entry) {
            throw_unless(
                is_array($entry)
                    && is_string($entry['selector'] ?? null)
                    && trim($entry['selector']) !== ''
                    && is_int($entry['id'] ?? null)
                    && $entry['id'] > 0
                    && ! isset($ids[$entry['id']]),
                RuntimeException::class,
                'The local prepared release record has invalid workflows.',
            );
            $ids[$entry['id']] = true;
            $workflows[] = new GitHubReleaseWorkflow($entry['selector'], $entry['id']);
        }

        return $workflows;
    }

    private function path(string $projectRoot): string
    {
        $process = new Process(
            ['git', 'rev-parse', '--path-format=absolute', '--git-path', 'maintainer-release.json'],
            $projectRoot,
        );
        $process->run();
        $path = trim($process->getOutput());

        throw_unless(
            $process->isSuccessful() && $path !== '',
            RuntimeException::class,
            'Git could not locate the local prepared release record.',
        );

        return $path;
    }
}
