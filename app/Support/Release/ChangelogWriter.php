<?php

namespace App\Support\Release;

use App\Support\Ai\ChangelogEntry;
use Illuminate\Filesystem\Filesystem;

final readonly class ChangelogWriter
{
    private const array HEADINGS = [
        'feat' => 'Features',
        'fix' => 'Fixes',
        'docs' => 'Documentation',
        'style' => 'Code Style',
        'refactor' => 'Refactoring',
        'perf' => 'Performance',
        'test' => 'Tests',
        'build' => 'Build',
        'ci' => 'Continuous Integration',
        'chore' => 'Maintenance',
        'revert' => 'Reverts',
    ];

    public function __construct(private Filesystem $files) {}

    /** @param  list<ChangelogEntry>  $entries */
    public function write(string $projectRoot, string $version, array $entries): string
    {
        $path = $projectRoot.DIRECTORY_SEPARATOR.'CHANGELOG.md';
        $groups = [];

        foreach ($entries as $entry) {
            $groups[$entry->type][] = $entry;
        }

        $section = ["## [{$version}] - ".date('Y-m-d')];

        foreach (self::HEADINGS as $type => $heading) {
            if (! isset($groups[$type])) {
                continue;
            }

            $section[] = '';
            $section[] = "### {$heading}";

            foreach ($groups[$type] as $entry) {
                $section[] = '';
                $section[] = "- **{$entry->title}** (`{$entry->hash}`)";
                $section[] = '  '.$entry->description;
            }
        }

        $existing = $this->files->isFile($path)
            ? trim($this->files->get($path))
            : '# Changelog';
        $heading = '# Changelog';
        $body = str_starts_with($existing, $heading)
            ? ltrim(substr($existing, strlen($heading)))
            : $existing;
        $contents = $heading."\n\n".implode("\n", $section);

        if ($body !== '') {
            $contents .= "\n\n".$body;
        }

        $this->files->put($path, $contents."\n");

        return $path;
    }
}
