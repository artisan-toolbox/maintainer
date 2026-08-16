<?php

namespace App\Support\Ai;

use App\Support\Release\SemanticVersionNumber;

interface ReleaseVersionRecommender
{
    public function recommend(
        string $provider,
        string $projectRoot,
        SemanticVersionNumber $latestVersion,
    ): ReleaseVersionRecommendation;
}
