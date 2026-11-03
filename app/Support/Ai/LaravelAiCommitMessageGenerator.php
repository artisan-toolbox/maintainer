<?php

namespace App\Support\Ai;

use App\Ai\Agents\CommitMessageAgent;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

final class LaravelAiCommitMessageGenerator implements CommitMessageGenerator
{
    private const int MAX_DIFF_BYTES = 24_000;

    private const int MAX_SECTION_BYTES = 4_000;

    public function generate(
        string $provider,
        string $status,
        string $diff,
        ?string $userContext = null,
    ): string {
        $context = filled($userContext) ? trim($userContext) : 'No additional user context was supplied.';
        $prompt = $this->prompt($context, $status, $diff, self::MAX_SECTION_BYTES, self::MAX_DIFF_BYTES);
        $agent = CommitMessageAgent::make();

        try {
            $response = $agent->prompt($prompt, provider: $provider);
        } catch (RequestException $exception) {
            $error = $exception->response->json('error.message');

            throw_if($exception->response->status() !== 400 || (
                $exception->response->json('error.code') !== 'context_length_exceeded'
                && (! is_string($error) || ! str_contains(strtolower($error), 'context window'))
            ), $exception);

            $retryPrompt = $this->prompt($context, $status, $diff, 1_000, 6_000);

            throw_if(strlen($retryPrompt) >= strlen($prompt), $exception);

            $response = $agent->prompt($retryPrompt, provider: $provider);
        }

        $message = trim($response->text);

        throw_if($message === '', RuntimeException::class, 'The AI provider returned an empty commit message.');

        return $message;
    }

    private function prompt(string $context, string $status, string $diff, int $sectionBytes, int $diffBytes): string
    {
        $context = $this->excerpt($context, $sectionBytes, 'user context');
        $status = $this->excerpt($status, $sectionBytes, 'status');
        $diff = $this->diffExcerpt($diff, $diffBytes);

        return <<<PROMPT
            Write the Git commit message for the selected changes.

            USER CONTEXT
            {$context}

            GIT STATUS
            {$status}

            GIT DIFF
            {$diff}
            PROMPT;
    }

    private function diffExcerpt(string $diff, int $maxBytes): string
    {
        if (strlen($diff) <= $maxBytes) {
            return $diff;
        }

        $details = new ReleaseDiffChunker(maxCharacters: $maxBytes)->chunk($diff);
        $source = implode('', $details->chunks);

        if (strlen($source) > $maxBytes) {
            return $this->excerpt($source, $maxBytes, 'diff content');
        }

        return $source."\n[Additional diff content omitted.]";
    }

    private function excerpt(string $text, int $maxBytes, string $section): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut(mb_scrub($text, 'UTF-8'), 0, $maxBytes, 'UTF-8')."\n[Additional {$section} omitted.]";
    }
}
