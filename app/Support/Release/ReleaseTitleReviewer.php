<?php

namespace App\Support\Release;

use Closure;
use Laravel\Prompts\TextareaPrompt;

final readonly class ReleaseTitleReviewer
{
    /**
     * @param  (Closure(TextareaPrompt): string)|null  $prompt
     */
    public function __construct(private ?Closure $prompt = null) {}

    public function review(string $version, string $generatedTitle): string
    {
        $prefix = "{$version} - ";
        $prompt = new TextareaPrompt(
            label: 'Review the GitHub release title',
            placeholder: "{$prefix}Describe the main outcome",
            default: $generatedTitle,
            required: 'A release title is required.',
            validate: static function (string $value) use ($prefix): ?string {
                $title = trim($value);
                $outcome = trim(substr($title, strlen($prefix)));

                if ($title === '') {
                    return 'A release title is required.';
                }

                if (! str_starts_with($title, $prefix) || $outcome === '') {
                    return "The release title must use {$prefix}followed by a compact outcome.";
                }

                if (str_contains($title, "\n") || mb_strlen($outcome) > 100) {
                    return 'The release outcome must be a single line with no more than 100 characters.';
                }

                return null;
            },
            hint: "Keep the {$prefix}prefix, edit as needed, then press Ctrl+D to finish.",
            rows: 3,
        );

        $title = $this->prompt === null
            ? $prompt->prompt()
            : ($this->prompt)($prompt);

        return trim($title);
    }
}
