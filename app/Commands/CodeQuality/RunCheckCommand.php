<?php

namespace App\Commands\CodeQuality;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('quality:check {--tool=* : Run only selected configured tools}')]
#[Description('Run the configured code-quality checks for continuous integration')]
final class RunCheckCommand extends RunCodeQualityWorkflowCommand
{
    protected function configurationKey(): string
    {
        return 'quality.test';
    }

    protected function workflowLabel(): string
    {
        return 'CI checks';
    }
}
