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
final class ReleaseChangelogAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            Build a detailed changelog entry list from the supplied commit summary and consolidated diff summaries.
            Use Conventional Commit types: feat, fix, docs, style, refactor, perf, test, build, ci,
            chore, or revert. Create one entry for every supplied commit and copy its abbreviated hash
            exactly. Never create changelog entries for generated release-preparation changes that do
            not have a supplied commit hash. Write concise titles and thorough functional descriptions that explain what
            changed, why it matters, user impact, compatibility, and migration needs when supported.
            Do not omit meaningful implementation, removal, documentation, test, build, or CI work.
            Never invent commits, hashes, behavior, or results.
            INSTRUCTIONS;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'entries' => $schema->array()->items(
                $schema->object([
                    'type' => $schema->string()
                        ->enum(['feat', 'fix', 'docs', 'style', 'refactor', 'perf', 'test', 'build', 'ci', 'chore', 'revert'])
                        ->required(),
                    'hash' => $schema->string()->description('One exact abbreviated commit hash supplied in the prompt.')->required(),
                    'title' => $schema->string()->description('A concise title for the change.')->required(),
                    'description' => $schema->string()->description('A detailed functional explanation of the change.')->required(),
                ])->withoutAdditionalProperties(),
            )->required(),
        ];
    }
}
