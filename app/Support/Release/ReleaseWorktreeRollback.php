<?php

namespace App\Support\Release;

final class ReleaseWorktreeRollback
{
    private ?ReleaseGitRepository $git = null;

    private ?string $projectRoot = null;

    private ?string $baseline = null;

    private bool $pushed = false;

    private bool $attempted = false;

    public function arm(ReleaseGitRepository $git, string $projectRoot, string $baseline): void
    {
        $this->git = $git;
        $this->projectRoot = $projectRoot;
        $this->baseline = $baseline;
        $this->pushed = false;
        $this->attempted = false;
    }

    public function markPushed(): void
    {
        $this->pushed = true;
    }

    public function isArmed(): bool
    {
        return $this->git !== null && $this->projectRoot !== null && $this->baseline !== null;
    }

    public function wasPushed(): bool
    {
        return $this->pushed;
    }

    public function rollback(): bool
    {
        $git = $this->git;
        $projectRoot = $this->projectRoot;
        $baseline = $this->baseline;

        if ($git === null || $projectRoot === null || $baseline === null || $this->pushed || $this->attempted) {
            return false;
        }

        $this->attempted = true;
        $git->rollback($projectRoot, $baseline);

        return true;
    }

    public function disarm(): void
    {
        $this->git = null;
        $this->projectRoot = null;
        $this->baseline = null;
        $this->pushed = false;
        $this->attempted = false;
    }
}
