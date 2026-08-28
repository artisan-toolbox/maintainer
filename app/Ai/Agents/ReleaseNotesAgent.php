<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[UseCheapestModel]
final class ReleaseNotesAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            Write GitHub release notes from the supplied version, commit summary, and validated changelog context.
            Return a concise title that describes only the release's main outcome. Do not include the version
            or tag in the title because the caller adds the exact release tag deterministically.
            The body must be clear, detailed Markdown organized by relevant sections such as Added,
            Changed, Fixed, Removed, Performance, Documentation, Testing, or Internal Maintenance.
            Include only sections supported by the input. Explain user-visible behavior, compatibility,
            migration requirements, removals, and important internal work when present.
            Never invent changes, issue numbers, benchmarks, migration steps, or test results.
            Do not repeat the title as the first heading in the body.
            INSTRUCTIONS;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A compact description of the release outcome without its version or tag.')->required(),
            'body' => $schema->string()->description('Detailed GitHub-flavored Markdown release notes.')->required(),
        ];
    }
}
