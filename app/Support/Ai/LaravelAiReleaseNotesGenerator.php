<?php

namespace App\Support\Ai;

use App\Ai\Agents\ReleaseNotesAgent;
use App\Support\Release\ReleaseChangeSet;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class LaravelAiReleaseNotesGenerator implements ReleaseNotesGenerator
{
    public function __construct(
        private ConfiguredAiModel $models,
        private AiReportLocale $locales,
    ) {}

    public function generate(string $provider, string $version, ReleaseChangeSet $changes): ReleaseNotes
    {
        $locale = $this->locales->forProjectArtifacts();
        $response = ReleaseNotesAgent::make()->prompt(
            <<<PROMPT
            Create the GitHub release notes for version {$version}.
            Write the title, headings, and items in the language of project locale {$locale}.

            COMMITS
            {$changes->commits}

            RELEASE CHANGE SUMMARY
            {$changes->diff}
            PROMPT,
            provider: $provider,
            model: $this->models->for('release_notes', $provider),
        );

        throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'The AI provider did not return structured release notes.');

        $title = $this->line($response['title'] ?? null, 'release title');
        $sections = $response['sections'] ?? null;

        throw_unless(
            is_array($sections) && array_is_list($sections) && $sections !== [],
            RuntimeException::class,
            'The AI provider returned invalid release notes sections.',
        );

        return new ReleaseNotes($this->title($version, $title), $this->body($sections, $locale));
    }

    /**
     * @param  non-empty-list<mixed>  $sections
     */
    private function body(array $sections, string $locale): string
    {
        $rendered = [];
        $headings = ReleaseNotesAgent::SECTION_HEADINGS;
        $english = $locale === 'en' || str_starts_with($locale, 'en_');

        foreach ($sections as $section) {
            $type = is_array($section) ? ($section['type'] ?? null) : null;
            $items = is_array($section) ? ($section['items'] ?? null) : null;

            throw_unless(
                is_string($type) && isset($headings[$type]) && ! isset($rendered[$type])
                && is_array($items) && array_is_list($items) && $items !== [],
                RuntimeException::class,
                'The AI provider returned an invalid or repeated release notes section.',
            );

            $heading = $this->line($section['heading'] ?? null, 'release section heading');
            $bullets = array_map(
                fn (mixed $item): string => '- '.$this->line($item, 'release section item'),
                $items,
            );

            $rendered[$type] = '## '.($english ? $headings[$type] : $heading)."\n\n".implode("\n", $bullets);
        }

        return implode("\n\n", array_replace(array_intersect_key($headings, $rendered), $rendered));
    }

    private function line(mixed $value, string $field): string
    {
        throw_unless(
            is_string($value) && trim($value) !== ''
            && preg_match('/[\p{Cc}\p{Zl}\p{Zp}]|^\s*(?:#{1,6}\s|[-*+]\s|\d+[.)]\s|>|`{3}|~{3})/u', $value) === 0,
            RuntimeException::class,
            "The AI provider returned an invalid {$field}.",
        );

        return trim($value);
    }

    private function title(string $version, string $title): string
    {
        $summary = trim($title);
        $versionPattern = preg_quote($version, '/');
        $summary = preg_replace(
            "/^(?:v?{$versionPattern})(?:\\s*[-–—:]\\s*|\\s+)/iu",
            '',
            $summary,
        ) ?? $summary;
        $summary = preg_replace('/^[\s\-–—:]+|[\s\-–—:]+$/u', '', $summary) ?? trim($summary);

        if ($summary === '') {
            $summary = 'Release';
        }

        return "{$version} - ".Str::limit($summary, 100, '…');
    }
}
