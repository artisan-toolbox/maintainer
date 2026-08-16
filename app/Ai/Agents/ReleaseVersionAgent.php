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
final class ReleaseVersionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            You recommend the next stable release increment by applying Semantic Versioning 2.0.0 to a Git diff.
            Return "patch" when the changes only contain backward-compatible bug fixes, documentation,
            tests, refactoring, maintenance, performance improvements, or internal implementation changes
            that do not add backward-compatible public functionality.
            Return "minor" when the changes add backward-compatible public functionality or deprecate a
            public API without removing it.
            Never recommend a major release. The caller has already determined that only patch and minor
            releases are available on the current major branch. If the diff appears to contain a breaking
            change, recommend minor as the safest available default and explicitly identify the breaking
            change in the justification so the maintainer can review it before proceeding.
            Base the decision only on the supplied diff. Do not invent changes, behavior, or compatibility claims.
            Write a concise, plain-English justification that identifies the evidence behind the recommendation.
            INSTRUCTIONS;
    }

    /**
     * Define the structured release recommendation returned by the agent.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'release_increment' => $schema->string()
                ->enum(['patch', 'minor'])
                ->description('The recommended stable Semantic Versioning increment.')
                ->required(),
            'justification' => $schema->string()
                ->description('A concise explanation grounded in the supplied Git diff.')
                ->required(),
        ];
    }
}
