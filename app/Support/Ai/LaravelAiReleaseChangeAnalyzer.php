<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseDiffSummaryAgent;
use App\Support\Release\ReleaseChangeSet;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class LaravelAiReleaseChangeAnalyzer implements ReleaseChangeAnalyzer
{
    private const int MAX_SUMMARY_CHARACTERS = 1_000;

    private const int MAX_COMMIT_CHARACTERS = 12_000;

    public function __construct(private ReleaseDiffChunker $chunker) {}

    public function analyze(string $provider, ReleaseChangeSet $changes): ReleaseChangeSet
    {
        $context = $this->chunker->chunk($changes->diff);
        $summaries = [];

        foreach ($context->chunks as $index => $chunk) {
            $number = $index + 1;
            $total = count($context->chunks);
            $response = ReleaseDiffSummaryAgent::make()->prompt(
                <<<PROMPT
                Summarize release diff fragment {$number} of {$total}.

                GIT DIFF FRAGMENT
                {$chunk}
                PROMPT,
                provider: $provider,
            );

            throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return a structured diff summary.');

            $summary = $response['summary'] ?? null;

            throw_unless(is_string($summary) && trim($summary) !== '', RuntimeException::class, 'The AI provider returned an empty diff summary.');

            $summaries[] = sprintf(
                'Fragment %d: %s',
                $number,
                Str::limit(trim($summary), self::MAX_SUMMARY_CHARACTERS, '…'),
            );
        }

        if ($context->omittedFiles !== []) {
            $summaries[] = Str::limit(
                'Generated or dependency files omitted from AI diff analysis: '.implode(', ', $context->omittedFiles).'.',
                self::MAX_SUMMARY_CHARACTERS,
                '…',
            );
        }

        if ($context->truncated) {
            $summaries[] = 'Additional diff fragments exceeded Maintainer\'s bounded analysis limit and were omitted. Commit summaries remain available below.';
        }

        return new ReleaseChangeSet(
            implode("\n\n", $summaries),
            Str::limit($changes->commits, self::MAX_COMMIT_CHARACTERS, "\n… additional commits omitted"),
        );
    }
}
