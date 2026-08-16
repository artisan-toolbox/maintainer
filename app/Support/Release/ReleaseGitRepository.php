<?php

namespace App\Support\Release;

interface ReleaseGitRepository
{
    public function head(string $projectRoot): string;

    public function changesSince(string $projectRoot, ?string $base): ReleaseChangeSet;

    public function stageAll(string $projectRoot): void;

    public function commit(string $projectRoot, string $version): string;

    public function push(string $projectRoot): void;

    public function rollback(string $projectRoot, string $reference): void;
}
