<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseChangelogAgent;
use App\Support\Release\ReleaseChangeSet;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final class LaravelAiReleaseChangelogGenerator implements ReleaseChangelogGenerator
{
    private const array TYPES = ['feat', 'fix', 'docs', 'style', 'refactor', 'perf', 'test', 'build', 'ci', 'chore', 'revert'];

    public function generate(string $provider, string $version, ReleaseChangeSet $changes): array
    {
        $response = ReleaseChangelogAgent::make()->prompt(
            <<<PROMPT
            Build the changelog entries for version {$version}.

            COMMITS
            {$changes->commits}

            RELEASE CHANGE SUMMARY
            {$changes->diff}
            PROMPT,
            provider: $provider,
        );

        throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return a structured changelog.');

        $entries = $response['entries'] ?? null;

        throw_unless(is_array($entries) && $entries !== [], RuntimeException::class, 'The AI provider returned an empty changelog.');

        return array_values(array_map($this->entry(...), $entries));
    }

    private function entry(mixed $entry): ChangelogEntry
    {
        throw_unless(is_array($entry), RuntimeException::class, 'The AI provider returned an invalid changelog entry.');

        $type = $entry['type'] ?? null;
        $hash = $entry['hash'] ?? null;
        $title = $entry['title'] ?? null;
        $description = $entry['description'] ?? null;

        throw_unless(is_string($type) && in_array($type, self::TYPES, true), RuntimeException::class, 'The AI provider returned an invalid changelog type.');
        throw_unless(is_string($hash) && trim($hash) !== '', RuntimeException::class, 'The AI provider returned an empty changelog commit hash.');
        throw_unless(is_string($title) && trim($title) !== '', RuntimeException::class, 'The AI provider returned an empty changelog title.');
        throw_unless(is_string($description) && trim($description) !== '', RuntimeException::class, 'The AI provider returned an empty changelog description.');

        return new ChangelogEntry($type, trim($hash), trim($title), trim($description));
    }
}
