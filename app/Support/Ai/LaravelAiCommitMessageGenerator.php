<?php

namespace App\Support\Ai;

use App\Ai\Agents\CommitMessageAgent;
use RuntimeException;

final class LaravelAiCommitMessageGenerator implements CommitMessageGenerator
{
    public function generate(
        string $provider,
        string $status,
        string $diff,
        ?string $userContext = null,
    ): string {
        $context = filled($userContext) ? trim($userContext) : 'No additional user context was supplied.';
        $prompt = <<<PROMPT
            Write the Git commit message for the selected changes.

            USER CONTEXT
            {$context}

            GIT STATUS
            {$status}

            GIT DIFF
            {$diff}
            PROMPT;

        $message = trim(CommitMessageAgent::make()->prompt(
            $prompt,
            provider: $provider,
        )->text);

        throw_if($message === '', RuntimeException::class, 'The AI provider returned an empty commit message.');

        return $message;
    }
}
