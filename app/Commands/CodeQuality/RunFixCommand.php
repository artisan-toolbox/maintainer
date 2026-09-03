<?php

namespace App\Commands\CodeQuality;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('quality:fix {--tool=* : Run only selected configured tools}')]
#[Description('Apply automatic code-quality fixes with the configured tools')]
final class RunFixCommand extends RunCodeQualityWorkflowCommand
{
    protected function configurationKey(): string
    {
        return 'quality.fix';
    }

    protected function workflowLabel(): string
    {
        return 'Code quality fixes';
    }

    protected function offersCommit(): bool
    {
        return true;
    }

    protected function offersCheck(): bool
    {
        return true;
    }
}
