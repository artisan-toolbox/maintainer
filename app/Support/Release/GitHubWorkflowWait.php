<?php

namespace App\Support\Release;

final class GitHubWorkflowWait
{
    public ?string $url = null;

    /** @var array{string|null, int|null, int|null}|null */
    public ?array $runIdentity = null;

    /** @var list<string> */
    public array $activeJobs = [];

    public ?GitHubWorkflowProgress $lastProgress = null;

    public int|float $nextJobRefresh = 0;

    public int|float $lastReportedAt = 0;

    public bool $passed = false;

    public function __construct(public readonly GitHubReleaseWorkflow $workflow) {}
}
