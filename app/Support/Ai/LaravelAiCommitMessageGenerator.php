<?php

namespace App\Support\Ai;

use App\Ai\Agents\CommitMessageAgent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class LaravelAiCommitMessageGenerator implements CommitMessageGenerator
{
    private const int MAX_DIFF_BYTES = 24_000;

    private const int MAX_SECTION_BYTES = 4_000;

    private const int SUBJECT_WIDTH = 72;

    public function __construct(
        private ConfiguredAiModel $models,
        private AiReportLocale $locales,
    ) {}

    public function generate(
        string $provider,
        string $status,
        string $diff,
        ?string $userContext = null,
    ): string {
        $context = filled($userContext) ? trim($userContext) : 'No additional user context was supplied.';
        $locale = $this->locales->forProjectArtifacts();
        $prompt = $this->prompt($locale, $context, $status, $diff, self::MAX_SECTION_BYTES, self::MAX_DIFF_BYTES);
        $agent = CommitMessageAgent::make();
        $model = $this->models->for('commit_message', $provider);

        try {
            $response = $agent->prompt($prompt, provider: $provider, model: $model);
        } catch (RequestException $exception) {
            $error = $exception->response->json('error.message');

            throw_if($exception->response->status() !== 400 || (
                $exception->response->json('error.code') !== 'context_length_exceeded'
                && (! is_string($error) || ! str_contains(strtolower($error), 'context window'))
            ), $exception);

            $retryPrompt = $this->prompt($locale, $context, $status, $diff, 1_000, 6_000);

            throw_if(strlen($retryPrompt) >= strlen($prompt), $exception);

            $response = $agent->prompt($retryPrompt, provider: $provider, model: $model);
        }

        throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return a structured commit message.');

        return $this->format($response->toArray(), $context);
    }

    /** @param array<array-key, mixed> $message */
    private function format(array $message, string $context): string
    {
        $type = $this->type($message['type'] ?? null);
        $scope = $message['scope'] ?? null;
        $breaking = $message['breaking_change'] ?? null;
        $title = rtrim($this->line($message['title'] ?? null, 'title'), '. ');
        $types = implode('|', CommitMessageAgent::TYPES);
        $changes = $message['changes'] ?? null;
        $trailers = $message['trailers'] ?? null;

        throw_if($scope !== null && (! is_string($scope) || preg_match('~\A[a-z0-9][a-z0-9._/-]{0,31}\z~i', $scope) !== 1), RuntimeException::class, 'The AI provider returned an invalid commit scope.');
        throw_if($title === '', RuntimeException::class, 'The AI provider returned an empty commit title.');
        throw_if(preg_match("~\A(?:{$types})(?:\([^()]*\))?!?:\s~i", $title) === 1, RuntimeException::class, 'The AI provider included a Conventional Commit prefix in the title.');
        throw_unless(is_array($changes) && array_is_list($changes) && is_array($trailers) && array_is_list($trailers), RuntimeException::class, 'The AI provider returned invalid commit changes or trailers.');

        $prefix = $type.($scope === null ? '' : "({$scope})").($breaking === null ? '' : '!').': ';
        $sections = [$prefix.Str::limit($title, self::SUBJECT_WIDTH - strlen($prefix), '')];
        $bullets = [];

        foreach ($changes as $change) {
            throw_unless(is_array($change), RuntimeException::class, 'The AI provider returned an invalid commit change.');
            $changeType = $this->type($change['type'] ?? null);
            $description = $this->line($change['description'] ?? null, 'change description');
            $bullets[] = wordwrap("- {$changeType}: {$description}", self::SUBJECT_WIDTH - 2, "\n  ");
        }

        if ($bullets !== []) {
            $sections[] = implode("\n", $bullets);
        }

        $footer = $breaking === null ? [] : ['BREAKING CHANGE: '.$this->line($breaking, 'breaking change')];
        $contextLines = array_map(trim(...), preg_split('/\R/', $context) ?: []);

        foreach ($trailers as $trailer) {
            throw_unless(is_array($trailer), RuntimeException::class, 'The AI provider returned an invalid commit trailer.');
            $token = $trailer['token'] ?? null;
            throw_unless(in_array($token, CommitMessageAgent::TRAILER_TOKENS, true), RuntimeException::class, 'The AI provider returned an unsupported commit trailer.');
            $value = $this->line($trailer['value'] ?? null, 'trailer value');
            $line = "{$token}: {$value}";

            if (in_array($line, $contextLines, true)) {
                $footer[] = $line;
            }
        }

        if ($footer !== []) {
            $sections[] = implode("\n", array_unique($footer));
        }

        return implode("\n\n", $sections);
    }

    private function type(mixed $type): string
    {
        throw_unless(is_string($type) && in_array($type, CommitMessageAgent::TYPES, true), RuntimeException::class, 'The AI provider returned an unsupported commit type.');

        return $type;
    }

    private function line(mixed $value, string $field): string
    {
        throw_unless(is_string($value) && trim($value) !== '' && preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 0, RuntimeException::class, "The AI provider returned an invalid commit {$field}.");

        return trim($value);
    }

    private function prompt(string $locale, string $context, string $status, string $diff, int $sectionBytes, int $diffBytes): string
    {
        $context = $this->excerpt($context, $sectionBytes, 'user context');
        $status = $this->excerpt($status, $sectionBytes, 'status');
        $diff = $this->diffExcerpt($diff, $diffBytes);

        return <<<PROMPT
            Write the Git commit message for the selected changes.
            Write the message text in the language of project locale {$locale}. Keep Conventional Commit syntax unchanged.

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
