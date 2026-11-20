<?php

namespace App\Support\Ai;

use App\Support\Release\SemanticVersionNumber;

interface ReleaseVersionRecommender
{
    /** @param array<string, string> $options */
    public function recommend(
        string $provider,
        string $projectRoot,
        SemanticVersionNumber $latestVersion,
        array $options,
    ): ?ReleaseVersionRecommendation;
}
