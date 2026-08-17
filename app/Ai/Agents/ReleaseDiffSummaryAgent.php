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
final class ReleaseDiffSummaryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            Summarize one bounded fragment of a Git release diff for other release-writing agents.
            Identify the concrete behavior added, changed, fixed, removed, documented, tested, or maintained.
            Preserve filenames, public API names, configuration keys, commands, and compatibility or migration
            implications when present. Clearly identify possible breaking changes. Do not reproduce patches,
            speculate, or invent behavior. Be concise while retaining functional details.
            INSTRUCTIONS;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('A concise, evidence-based functional summary of this diff fragment.')
                ->required(),
        ];
    }
}
