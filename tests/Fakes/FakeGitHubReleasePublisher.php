<?php

namespace Tests\Fakes;

use App\Support\Release\GitHubReleasePublisher;

final class FakeGitHubReleasePublisher implements GitHubReleasePublisher
{
    /** @var array{version: string, target: string, title: string, body: string, prerelease: bool}|null */
    public ?array $published = null;

    public ?string $verifiedTagRepository = null;

    public function publish(
        string $projectRoot,
        string $version,
        string $target,
        string $title,
        string $body,
        bool $prerelease,
        ?string $verifiedTagRepository = null,
    ): string {
        $this->published = compact('version', 'target', 'title', 'body', 'prerelease');
        $this->verifiedTagRepository = $verifiedTagRepository;

        return "https://github.com/example/project/releases/tag/{$version}";
    }
}
