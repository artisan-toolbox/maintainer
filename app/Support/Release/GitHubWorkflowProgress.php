<?php

namespace App\Support\Release;

final readonly class GitHubWorkflowProgress
{
    /** @param list<string> $activeJobs */
    public function __construct(
        public string $workflow,
        public ?string $status,
        public ?string $conclusion,
        public ?string $url,
        public int $elapsedSeconds,
        public array $activeJobs,
        public ?int $attempt = null,
    ) {}
}
