<?php

namespace App\Support\Git;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

use function Illuminate\Filesystem\join_paths;

final readonly class GitignoreManager
{
    public function __construct(private Filesystem $files) {}

    /**
     * Add missing entries while preserving the existing file and line endings.
     *
     * @param  list<string>  $entries
     * @return list<string> Entries that were added.
     */
    public function add(string $projectRoot, array $entries): array
    {
        $path = join_paths($projectRoot, '.gitignore');
        $contents = $this->files->exists($path) ? $this->files->get($path) : '';
        $existingEntries = array_map(trim(...), preg_split('/\R/', $contents) ?: []);
        $missingEntries = [];

        foreach (array_unique($entries) as $entry) {
            $entry = trim($entry);

            if ($entry === '' || in_array($entry, $existingEntries, true)) {
                continue;
            }

            $missingEntries[] = $entry;
            $existingEntries[] = $entry;
        }

        if ($missingEntries === []) {
            return [];
        }

        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        if ($contents !== '' && ! str_ends_with($contents, "\n") && ! str_ends_with($contents, "\r")) {
            $contents .= $lineEnding;
        }

        $contents .= implode($lineEnding, $missingEntries).$lineEnding;

        throw_if(
            $this->files->put($path, $contents) === false,
            RuntimeException::class,
            'Unable to update .gitignore.',
        );

        return $missingEntries;
    }
}
