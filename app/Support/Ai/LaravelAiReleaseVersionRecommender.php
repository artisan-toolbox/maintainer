<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseVersionAgent;
use App\Support\Diff\GitDiffGenerator;
use App\Support\Release\SemanticVersionNumber;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class LaravelAiReleaseVersionRecommender implements ReleaseVersionRecommender
{
    public function __construct(
        private GitDiffGenerator $diffGenerator,
        private ReleaseDiffChunker $chunker,
    ) {}

    public function recommend(
        string $provider,
        string $projectRoot,
        SemanticVersionNumber $latestVersion,
    ): ReleaseVersionRecommendation {
        $diff = $this->diffGenerator->generate($projectRoot, $latestVersion->value());
        $context = $this->chunker->chunk($diff);
        $recommendations = [];

        foreach ($context->chunks as $index => $chunk) {
            $number = $index + 1;
            $total = count($context->chunks);
            $response = ReleaseVersionAgent::make()->prompt(
                <<<PROMPT
                Recommend the next stable release increment for fragment {$number} of {$total} since {$latestVersion->value()}.

                GIT DIFF FRAGMENT
                {$chunk}
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

            $recommendations[] = new ReleaseVersionRecommendation(
                $releaseIncrement,
                Str::limit(trim($justification), 500, '…'),
            );
        }

        $increment = collect($recommendations)->contains(
            fn (ReleaseVersionRecommendation $recommendation): bool => $recommendation->increment === ReleaseIncrement::Minor,
        ) ? ReleaseIncrement::Minor : ReleaseIncrement::Patch;
        $justifications = collect($recommendations)
            ->where('increment', $increment)
            ->pluck('justification')
            ->unique()
            ->implode(' ');

        if ($context->truncated) {
            $justifications .= ' Maintainer reached its bounded diff analysis limit; review the remaining changes before accepting this recommendation.';
        }

        return new ReleaseVersionRecommendation($increment, Str::limit($justifications, 2_000, '…'));
    }
}
