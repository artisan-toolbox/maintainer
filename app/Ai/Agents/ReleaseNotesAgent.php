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
            The title must be concise but specific enough to identify the release's main outcome.
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
            'title' => $schema->string()->description('A concise and descriptive GitHub release title.')->required(),
            'body' => $schema->string()->description('Detailed GitHub-flavored Markdown release notes.')->required(),
        ];
    }
}
