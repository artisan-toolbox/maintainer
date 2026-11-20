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
final class CommitMessageAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const array TYPES = ['feat', 'fix', 'docs', 'style', 'refactor', 'perf', 'test', 'build', 'ci', 'chore', 'revert'];

    public const array TRAILER_TOKENS = ['Refs', 'Fixes', 'Closes', 'Resolves', 'Co-authored-by'];

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            Describe the selected changes using the fields for a Conventional Commit.
            Choose the main change type for the subject. Write the title in the imperative without its type prefix or a trailing period.
            Keep the title short enough that the composed subject fits in 72 characters.
            Use a concise scope only when the changed component is unambiguous. Use null otherwise.
            Put distinct changes that add useful detail in the changes list, each with its own type.
            Write each change description as one line without a bullet or type prefix. Use an empty list when the title suffices.
            Explain an actual breaking change in breaking_change. Use null for compatible changes.
            Include issue and co-author trailers only when explicitly supplied as complete trailer lines in USER CONTEXT.
            Never claim that tests passed unless the supplied context explicitly says so.
            INSTRUCTIONS;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(self::TYPES)->required(),
            'scope' => $schema->string()->nullable()->description('Component name using letters, numbers, or . _ / -.')->required(),
            'title' => $schema->string()->description('The concise subject without Conventional Commit syntax.')->required(),
            'breaking_change' => $schema->string()->nullable()->description('A one-line description of the breaking change, or null.')->required(),
            'changes' => $schema->array()->items(
                $schema->object([
                    'type' => $schema->string()->enum(self::TYPES)->required(),
                    'description' => $schema->string()->required(),
                ])->withoutAdditionalProperties(),
            )->required(),
            'trailers' => $schema->array()->items(
                $schema->object([
                    'token' => $schema->string()->enum(self::TRAILER_TOKENS)->required(),
                    'value' => $schema->string()->required(),
                ])->withoutAdditionalProperties(),
            )->required(),
        ];
    }
}
