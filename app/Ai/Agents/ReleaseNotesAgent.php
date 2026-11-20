<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Strict]
#[UseCheapestModel]
final class ReleaseNotesAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const array SECTION_HEADINGS = [
        'added' => 'Added',
        'changed' => 'Changed',
        'fixed' => 'Fixed',
        'removed' => 'Removed',
        'performance' => 'Performance',
        'documentation' => 'Documentation',
        'testing' => 'Testing',
        'maintenance' => 'Internal Maintenance',
    ];

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            Write GitHub release notes from the supplied version, commit summary, and validated changelog context.
            Return a concise title that describes only the release's main outcome. Do not include the version
            or tag in the title because the caller adds the exact release tag deterministically.
            Group changes into the section types defined in the schema. Use each section type at most once.
            Supply a short localized heading and one single-line sentence per item. Leave heading and list
            markers to the caller. You may use inline Markdown for code identifiers.
            Include only sections supported by the input. Explain user-visible behavior, compatibility,
            migration requirements, removals, and important internal work when present.
            Never invent changes, issue numbers, benchmarks, migration steps, or test results.
            Do not repeat the title as a heading.
            INSTRUCTIONS;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A compact description of the release outcome without its version or tag.')->required(),
            'sections' => $schema->array()->items(
                $schema->object([
                    'type' => $schema->string()->enum(array_keys(self::SECTION_HEADINGS))->required(),
                    'heading' => $schema->string()->description('A short section heading in the project language.')->required(),
                    'items' => $schema->array()->items(
                        $schema->string()->description('One single-line release note without a list marker.'),
                    )->required(),
                ])->withoutAdditionalProperties(),
            )->required(),
        ];
    }
}
