<?php

namespace App\Support\Release;

interface GitHubReleaseSource
{
    /**
     * @return list<string>
     */
    public function versions(string $projectRoot): array;
}
