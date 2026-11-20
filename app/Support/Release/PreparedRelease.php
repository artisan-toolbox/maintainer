<?php

namespace App\Support\Release;

final readonly class PreparedRelease
{
    /** @param non-empty-list<GitHubReleaseWorkflow> $workflows */
    public function __construct(
        public string $repository,
        public array $workflows,
        public string $branch,
        public string $commit,
        public string $currentVersion,
        public string $version,
        public string $title,
        public string $body,
    ) {}

    /** @param list<GitHubReleaseWorkflow> $workflows */
    public function requiresWorkflows(array $workflows): bool
    {
        if (count($this->workflows) !== count($workflows)) {
            return false;
        }

        return array_all($this->workflows, static fn (GitHubReleaseWorkflow $required): bool => array_any(
            $workflows,
            $required->matches(...),
        ));
    }

    /** @param non-empty-list<GitHubReleaseWorkflow> $workflows */
    public function withWorkflows(array $workflows): self
    {
        return new self(
            $this->repository,
            $workflows,
            $this->branch,
            $this->commit,
            $this->currentVersion,
            $this->version,
            $this->title,
            $this->body,
        );
    }
}
