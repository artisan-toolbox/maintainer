<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseVersionAgent;
use App\Support\Diff\GitDiffGenerator;
use App\Support\Release\SemanticVersionNumber;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class LaravelAiReleaseVersionRecommender implements ReleaseVersionRecommender
{
    public function __construct(private GitDiffGenerator $diffGenerator) {}

    public function recommend(
        string $provider,
        string $projectRoot,
        SemanticVersionNumber $latestVersion,
    ): ReleaseVersionRecommendation {
        $diff = $this->diffGenerator->generate($projectRoot, $latestVersion->value());
        $response = ReleaseVersionAgent::make()->prompt(
            <<<PROMPT
            Recommend the next stable release increment for the changes since {$latestVersion->value()}.

            GIT DIFF
            {$diff}
            PROMPT,
            provider: $provider,
        );

        throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return a structured release recommendation.');

        $increment = $response['release_increment'] ?? null;
        $justification = $response['justification'] ?? null;

        throw_unless(is_string($increment), RuntimeException::class, 'The AI provider returned an invalid release increment.');
        throw_unless(is_string($justification) && trim($justification) !== '', RuntimeException::class, 'The AI provider returned an empty release justification.');

        $releaseIncrement = ReleaseIncrement::tryFrom($increment);

        throw_if($releaseIncrement === null, RuntimeException::class, 'The AI provider must recommend either patch or minor.');

        return new ReleaseVersionRecommendation($releaseIncrement, trim($justification));
    }
}
