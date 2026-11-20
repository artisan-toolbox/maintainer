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
        private ConfiguredAiModel $models,
        private AiReportLocale $locale,
        private AiConsoleProgress $progress,
    ) {}

    public function recommend(
        string $provider,
        string $projectRoot,
        SemanticVersionNumber $latestVersion,
        array $options,
    ): ?ReleaseVersionRecommendation {
        $diff = $this->diffGenerator->generate($projectRoot, $latestVersion->value());
        $isApplication = $this->projectTypeDetector->detect($projectRoot) === LaravelProjectType::Application;
        $context = $this->chunker->chunk($diff, omitDevelopmentAiFiles: $isApplication);

        if (! $context->hasAnalyzableChanges) {
            return null;
        }

        $projectScope = $isApplication
            ? 'Final Laravel application. Development-only AI tooling, repository workflows, and workstation or deployment setup are maintenance. Look for new behavior delivered by the application to its users.'
            : 'Reusable Laravel package. Look for a changed public API or shipped integration used by consuming projects. Maintainer-only tools, examples, and contributor instructions are internal.';
        $model = $this->models->for('release_type_suggestion', $provider);
        $locale = $this->locale->forReleaseRecommendation();
        $versions = array_keys($options);
        $stable = array_values(array_filter($versions, fn (string $version): bool => ! str_contains($version, '-')));
        $patch = $stable[0];
        $menu = implode("\n", $options);
        $decisions = [];
        $selected = $patch;
        $total = count($context->chunks);

        foreach ($context->chunks as $index => $chunk) {
            $path = $context->paths[$index] ?? '';
            $eligible = $this->allowsPublicChange($path, $chunk) ? $stable : [$patch];
            $number = $index + 1;
            $agent = new ReleaseVersionAgent($eligible, $options, $locale, $this->progress->fragment($number, $total));
            $response = $agent->prompt(
                <<<PROMPT
                Review release diff fragment {$number} of {$total} since {$latestVersion->value()}.
                {$projectScope}

                Available release options:
                {$menu}

                Changed file: {$path}
                GIT DIFF FRAGMENT
                {$chunk}
                PROMPT,
                provider: $provider,
                model: $model,
            );

            throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return a structured release recommendation.');
            $version = $response['recommended_version'] ?? null;
            throw_unless(
                array_key_exists('recommended_version', $response->toArray())
                    && ($version === null ? count($eligible) > 1 : is_string($version) && in_array($version, $eligible, true)),
                RuntimeException::class,
                'The recommended release version is ineligible for this change.',
            );

            $evidence = $response['public_change_evidence'] ?? null;
            throw_unless(is_string($evidence) && ($version !== $patch || $evidence === ''), RuntimeException::class, 'The recommendation contains invalid public change evidence.');
            if ($version !== $patch) {
                throw_unless(
                    preg_match('/^[+-](?!\+\+ |-- )\s*\S[^\r\n]*$/D', $evidence) === 1
                        && in_array($evidence, explode("\n", str_replace("\r\n", "\n", $chunk)), true),
                    RuntimeException::class,
                    'The recommendation did not cite a changed public contract in the diff.',
                );
                if ($selected !== null) {
                    $selected = $version;
                }
            }

            $reasons = $response['reasons'] ?? null;
            throw_unless(is_array($reasons) && count($reasons) === count($options), RuntimeException::class, 'The recommendation must explain every release option.');

            foreach ($versions as $option) {
                $reason = $reasons[$option] ?? null;
                throw_unless(is_string($reason) && trim($reason) !== '' && ! preg_match('/[\p{Cc}\p{Cf}]/u', $reason), RuntimeException::class, 'The recommendation contains a missing or invalid release reason.');
                $decisions[$version ?? 'major'][$option][] = Str::limit(trim($reason), 350, '…');
            }
        }

        $reasons = [];
        foreach ($decisions[$selected ?? 'major'] as $option => $explanations) {
            $reasons[$option] = Str::limit(implode(' ', array_unique($explanations)), 350, '…');
        }

        return new ReleaseVersionRecommendation($selected, $reasons, $context->truncated);
    }

    private function allowsPublicChange(string $path, string $chunk): bool
    {
        if (str_starts_with($path, '.github/workflows/')) {
            // A repository workflow becomes a consumer API when other repositories can call it.
            return preg_match('/^[+ -](?: +workflow_call\s*:|on\s*:\s*(?:workflow_call|\[.*\bworkflow_call\b.*\]))/m', $chunk) === 1;
        }

        if (str_ends_with(strtolower($path), '.md')) {
            return str_starts_with($path, 'resources/boost/skills/');
        }

        return $path !== '' && ! preg_match('~^(?:tests/|\.env\.(?:example|testing)$)~i', $path);
    }
}
