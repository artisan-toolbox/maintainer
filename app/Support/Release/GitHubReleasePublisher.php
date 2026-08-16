<?php

namespace App\Support\Release;

interface GitHubReleasePublisher
{
    public function publish(
        string $projectRoot,
        string $version,
        string $target,
        string $title,
        string $body,
        bool $prerelease,
    ): string;
}
