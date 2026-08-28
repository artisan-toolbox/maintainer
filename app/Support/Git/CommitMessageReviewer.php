<?php

namespace App\Support\Git;

use Closure;
use Laravel\Prompts\TextareaPrompt;

final readonly class CommitMessageReviewer
{
    /**
     * @param  (Closure(TextareaPrompt): string)|null  $prompt
     */
    public function __construct(private ?Closure $prompt = null) {}

    public function review(string $suggestedMessage, bool $generated): string
    {
        $prompt = new TextareaPrompt(
            label: $generated
                ? 'Review the generated commit message'
                : 'Write the commit message',
            placeholder: 'feat(scope): describe the change',
            default: $suggestedMessage,
            required: 'A commit message is required.',
            validate: static fn (string $value): ?string => trim($value) === ''
                ? 'A commit message is required.'
                : null,
            hint: 'Edit as needed, then press Ctrl+D to finish.',
            rows: 8,
        );

        $message = $this->prompt === null
            ? $prompt->prompt()
            : ($this->prompt)($prompt);

        return trim($message);
    }
}
