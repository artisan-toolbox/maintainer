<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseChangelogAgent;
use App\Support\Release\ReleaseChangeSet;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final class LaravelAiReleaseChangelogGenerator implements ReleaseChangelogGenerator
{
    private const array TYPES = ['feat', 'fix', 'docs', 'style', 'refactor', 'perf', 'test', 'build', 'ci', 'chore', 'revert'];

    public function generate(string $provider, string $version, ReleaseChangeSet $changes): array
    {
        $commits = $this->commits($changes->commits);

        throw_if($commits === [], RuntimeException::class, 'No Git commits were found for the release changelog.');

        $response = ReleaseChangelogAgent::make()->prompt(
            <<<PROMPT
            Build the changelog entries for version {$version}.

            HASH RULES
            Every entry must use exactly one hash from COMMITS. Return one entry for every commit.
            Ignore uncommitted version, badge, changelog, and build preparation changes from the release workflow.

            COMMITS
            {$changes->commits}

            RELEASE CHANGE SUMMARY
            {$changes->diff}
            PROMPT,
            provider: $provider,
        );

        throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return a structured changelog.');

        $entries = $response['entries'] ?? null;

        throw_unless(is_array($entries), RuntimeException::class, 'The AI provider returned an invalid changelog.');

        $result = [];
        $represented = [];

        foreach ($entries as $entry) {
            $validated = $this->entry($entry, array_keys($commits));

            if ($validated === null) {
                continue;
            }

            $result[] = $validated;
            $represented[$validated->hash] = true;
        }

        foreach ($commits as $hash => $subject) {
            if (! isset($represented[$hash])) {
                $result[] = $this->fallbackEntry($hash, $subject);
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $validHashes
     */
    private function entry(mixed $entry, array $validHashes): ?ChangelogEntry
    {
        throw_unless(is_array($entry), RuntimeException::class, 'The AI provider returned an invalid changelog entry.');

        $type = $entry['type'] ?? null;
        $hash = $entry['hash'] ?? null;
        $title = $entry['title'] ?? null;
        $description = $entry['description'] ?? null;

        if (! is_string($hash) || ! in_array(trim($hash), $validHashes, true)) {
            return null;
        }

        throw_unless(is_string($type) && in_array($type, self::TYPES, true), RuntimeException::class, 'The AI provider returned an invalid changelog type.');
        throw_unless(is_string($title) && trim($title) !== '', RuntimeException::class, 'The AI provider returned an empty changelog title.');
        throw_unless(is_string($description) && trim($description) !== '', RuntimeException::class, 'The AI provider returned an empty changelog description.');

        return new ChangelogEntry($type, trim($hash), trim($title), trim($description));
    }

    /** @return array<string, string> */
    private function commits(string $commits): array
    {
        $result = [];

        foreach (preg_split('/\R/', trim($commits), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $commit) {
            if (preg_match('/^([0-9a-f]{7,64})(?:\t|\s+)(.+)$/i', trim($commit), $matches) === 1) {
                $result[$matches[1]] = trim($matches[2]);
            }
        }

        return $result;
    }

    private function fallbackEntry(string $hash, string $subject): ChangelogEntry
    {
        $type = 'chore';
        $title = $subject;

        if (preg_match('/^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(?:\([^)]*\))?!?:\s*(.+)$/i', $subject, $matches) === 1) {
            $type = strtolower($matches[1]);
            $title = $matches[2];
        }

        return new ChangelogEntry(
            $type,
            $hash,
            Str::ucfirst(trim($title)),
            "Includes the changes introduced by commit {$hash}: {$subject}.",
        );
    }
}
