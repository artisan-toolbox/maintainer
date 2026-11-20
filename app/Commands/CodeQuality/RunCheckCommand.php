<?php

namespace App\Commands\CodeQuality;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('quality:check {--tool=* : Run only selected configured tools} {--strict : Require every selected tool to run}')]
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

    protected function requiresAllSelectedTools(): bool
    {
        return $this->option('strict') === true;
    }
}
