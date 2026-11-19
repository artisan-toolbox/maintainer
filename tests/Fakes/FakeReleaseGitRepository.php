<?php

namespace Tests\Fakes;

use App\Support\Release\PreparedRelease;
use App\Support\Release\ReleaseChangeSet;
use App\Support\Release\ReleaseGitRepository;

final class FakeReleaseGitRepository implements ReleaseGitRepository
{
    public bool $staged = false;

    public bool $committed = false;

    public bool $pushed = false;

    public ?PreparedRelease $pushedRelease = null;

    public ?PreparedRelease $taggedRelease = null;

    public bool $rolledBack = false;

    public ?string $changelogWhenStaged = null;

    /** @var list<string> */
    public array $ensuredReferences = [];

    public function head(string $projectRoot): string
    {
        return $this->committed ? str_repeat('a', 40) : 'baseline-head';
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
        $path = $projectRoot.'/CHANGELOG.md';
        $contents = is_file($path) ? file_get_contents($path) : null;
        $this->changelogWhenStaged = is_string($contents) ? $contents : null;
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

    public function githubOrigin(string $projectRoot): string
    {
        return 'github.com/example/project';
    }

    public function pushReleaseCommit(string $projectRoot, PreparedRelease $release): void
    {
        $this->pushedRelease = $release;
    }

    public function pushReleaseTag(string $projectRoot, PreparedRelease $release): void
    {
        $this->taggedRelease = $release;
    }

    public function rollback(string $projectRoot, string $reference): void
    {
        $this->rolledBack = true;
    }
}
