<?php

namespace App\Support\Release;

use RuntimeException;

final readonly class GitHubReleaseWorkflow
{
    public function __construct(
        public string $selector,
        public ?int $id = null,
    ) {}

    /** @return list<non-empty-string> */
    public static function configuredSelectors(mixed $configuration): array
    {
        if ($configuration === null) {
            return [];
        }

        $selectors = is_string($configuration) ? [$configuration] : $configuration;
        throw_unless(
            is_array($selectors) && array_is_list($selectors) && $selectors !== [],
            RuntimeException::class,
            'The release.workflow option must be a GitHub Actions workflow name or filename, a nonempty list of names or filenames, or null.',
        );

        $result = [];

        foreach ($selectors as $entry) {
            $selector = is_string($entry) ? trim($entry) : '';
            throw_if(
                $selector === '',
                RuntimeException::class,
                'Each release.workflow entry must be a nonempty GitHub Actions workflow name or filename.',
            );

            throw_if(in_array($selector, $result, true), RuntimeException::class, "Duplicate release.workflow entry: {$selector}.");

            $result[] = $selector;
        }

        return $result;
    }

    public function matches(self $current): bool
    {
        return $this->id === null ? $this->selector === $current->selector : $this->id === $current->id;
    }
}
