<?php

namespace Tests\Fakes;

use App\Support\Release\ReleaseChangeSet;
use App\Support\Release\ReleaseGitRepository;

final class FakeReleaseGitRepository implements ReleaseGitRepository
{
    public bool $staged = false;

    public bool $committed = false;

    public bool $pushed = false;

    public bool $rolledBack = false;

    /** @var list<string> */
    public array $ensuredReferences = [];

    public function head(string $projectRoot): string
    {
        return 'baseline-head';
    }

    public function ensureLocalReference(string $projectRoot, string $reference): void
    {
        $this->ensuredReferences[] = $reference;
    }

    public function changesSince(string $projectRoot, ?string $base): ReleaseChangeSet
    {
        return new ReleaseChangeSet(
            'diff --git a/src/ProjectVersion.php b/src/ProjectVersion.php',
            'abc1234 Add the release workflow',
        );
    }

    public function stageAll(string $projectRoot): void
    {
        $this->staged = true;
    }

    public function commit(string $projectRoot, string $version): string
    {
        $this->committed = true;

        return "abc1234 chore(release): prepare {$version}";
    }

    public function push(string $projectRoot): void
    {
        $this->pushed = true;
    }

    public function rollback(string $projectRoot, string $reference): void
    {
        $this->rolledBack = true;
    }
}
