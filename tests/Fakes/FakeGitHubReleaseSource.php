<?php

namespace Tests\Fakes;

use App\Support\Release\GitHubReleaseSource;

final class FakeGitHubReleaseSource implements GitHubReleaseSource
{
    /** @var list<string> */
    public array $releases = ['1.0.0'];

    public function versions(string $projectRoot): array
    {
        return $this->releases;
    }
}
