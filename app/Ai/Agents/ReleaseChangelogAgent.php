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
            chore, or revert. Preserve each real abbreviated commit hash exactly when a change maps
            to a commit. Write concise titles and thorough functional descriptions that explain what
            changed, why it matters, user impact, compatibility, and migration needs when supported.
            Consolidate duplicate changes, but do not omit meaningful implementation, removal,
            documentation, test, build, or CI work. Never invent commits, hashes, behavior, or results.
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
                    'hash' => $schema->string()->description('The abbreviated source commit hash.')->required(),
                    'title' => $schema->string()->description('A concise title for the change.')->required(),
                    'description' => $schema->string()->description('A detailed functional explanation of the change.')->required(),
                ])->withoutAdditionalProperties(),
            )->required(),
        ];
    }
}
