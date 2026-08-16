<?php

namespace App\Support\Release;

final readonly class LatestGitHubRelease
{
    public function __construct(
        private GitHubReleaseSource $source,
        private SemanticVersion $semanticVersion,
    ) {}

    public function forMajor(string $projectRoot, int $major): ?SemanticVersionNumber
    {
        $latest = null;

        foreach ($this->source->versions($projectRoot) as $version) {
            $candidate = $this->semanticVersion->parse($version);

            if ($candidate === null || $candidate->major !== $major) {
                continue;
            }

            if ($latest === null || $this->semanticVersion->compare($candidate, $latest) > 0) {
                $latest = $candidate;
            }
        }

        return $latest;
    }
}
