<?php

namespace App\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use LogicException;
use function Laravel\Prompts\select;

#[Signature('maintainer')]
#[Description('Open the Maintainer workflow menu')]
final class MaintainerCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $command = select(
            label: 'Which workflow would you like to run?',
            options: [
                'release:create' => 'Create a new GitHub release',
            ],
            default: 'release:create',
        );

        return match ($command) {
            'release:create' => $this->call('release:create'),
            default => throw new LogicException('The selected Maintainer workflow is not supported.'),
        };
    }
}
