<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseVersionAgent;
use App\Support\Diff\GitDiffGenerator;
use App\Support\Quality\LaravelProjectType;
use App\Support\Quality\LaravelProjectTypeDetector;
use App\Support\Release\SemanticVersionNumber;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class LaravelAiReleaseVersionRecommender implements ReleaseVersionRecommender
{
    public function __construct(
        private GitDiffGenerator $diffGenerator,
        private ReleaseDiffChunker $chunker,
        private LaravelProjectTypeDetector $projectTypeDetector,
    ) {}

    public function recommend(
        string $provider,
        string $projectRoot,
        SemanticVersionNumber $latestVersion,
    ): ReleaseVersionRecommendation {
        $diff = $this->diffGenerator->generate($projectRoot, $latestVersion->value());
        $isApplication = $this->projectTypeDetector->detect($projectRoot) === LaravelProjectType::Application;
        $context = $this->chunker->chunk($diff, omitDevelopmentAiFiles: $isApplication);
        $projectScope = $isApplication
            ? 'This is a final Laravel application. Development-only AI tooling, MCP integrations, instructions, static analysis, and test support are internal maintenance and must never be evidence for a minor release. Recommend minor only for new backward-compatible behavior delivered by the running application.'
            : 'This is a reusable Laravel package. Developer-facing capabilities, including relevant AI or MCP integration, can be public package functionality and should be evaluated from the supplied diff.';

        if ($isApplication && ! $context->hasAnalyzableChanges) {
            return new ReleaseVersionRecommendation(
                ReleaseIncrement::Patch,
                'Only development AI support, generated, dependency, or lock files remain after filtering the application diff; patch is the safest default.',
            );
        }

        $recommendations = [];

        foreach ($context->chunks as $index => $chunk) {
            $number = $index + 1;
            $total = count($context->chunks);
            $response = ReleaseVersionAgent::make()->prompt(
                <<<PROMPT
                Recommend the next stable release increment for fragment {$number} of {$total} since {$latestVersion->value()}.

                PROJECT RELEASE SCOPE
                {$projectScope}

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
