<?php

namespace App\Support\Git;

use Closure;
use Laravel\Prompts\MultiSearchPrompt;

final readonly class GitFileSelector
{
    /**
     * @param  (Closure(MultiSearchPrompt): list<string>)|null  $prompt
     */
    public function __construct(private ?Closure $prompt = null) {}

    /**
     * @param  list<GitChangedFile>  $files
     * @return list<GitChangedFile>
     */
    public function select(array $files): array
    {
        $options = [];

        foreach ($files as $index => $file) {
            $options["change-{$index}"] = $file->label;
        }

        $prompt = new MultiSearchPrompt(
            label: 'Which files should be included in the commit?',
            options: static function (string $search) use ($options): array {
                if ($search === '') {
                    return $options;
                }

                return array_filter(
                    $options,
                    static fn (string $label): bool => str_contains(strtolower($label), strtolower($search)),
                );
            },
            placeholder: 'Search changed files',
            scroll: 10,
            required: 'Select at least one file to commit.',
            hint: 'All files are selected by default. Use space to toggle a file.',
        );
        $prompt->values = $options;

        $selected = $this->prompt === null
            ? $prompt->prompt()
            : ($this->prompt)($prompt);

        return array_values(array_map(
            static fn (string $key): GitChangedFile => $files[(int) substr($key, strlen('change-'))],
            $selected,
        ));
    }
}
