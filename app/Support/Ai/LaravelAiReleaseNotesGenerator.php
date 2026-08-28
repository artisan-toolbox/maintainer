<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseNotesAgent;
use App\Support\Release\ReleaseChangeSet;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final class LaravelAiReleaseNotesGenerator implements ReleaseNotesGenerator
{
    public function generate(string $provider, string $version, ReleaseChangeSet $changes): ReleaseNotes
    {
        $response = ReleaseNotesAgent::make()->prompt(
            <<<PROMPT
            Create the GitHub release notes for version {$version}.

            COMMITS
            {$changes->commits}

            RELEASE CHANGE SUMMARY
            {$changes->diff}
            PROMPT,
            provider: $provider,
        );

        throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return structured release notes.');

        $title = $response['title'] ?? null;
        $body = $response['body'] ?? null;

        throw_unless(is_string($title) && trim($title) !== '', RuntimeException::class, 'The AI provider returned an empty release title.');
        throw_unless(is_string($body) && trim($body) !== '', RuntimeException::class, 'The AI provider returned an empty release body.');

        return new ReleaseNotes($this->title($version, $title), trim($body));
    }

    private function title(string $version, string $title): string
    {
        $summary = trim($title);
        $versionPattern = preg_quote($version, '/');
        $summary = preg_replace(
            "/^(?:v?{$versionPattern})(?:\\s*[-–—:]\\s*|\\s+)/iu",
            '',
            $summary,
        ) ?? $summary;
        $summary = preg_replace('/^[\s\-–—:]+|[\s\-–—:]+$/u', '', $summary) ?? trim($summary);

        if ($summary === '') {
            $summary = 'Release';
        }

        return "{$version} - ".Str::limit($summary, 100, '…');
    }
}
