<?php

namespace App\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;

#[Signature('release:create')]
#[Description('Create a new GitHub release for the project')]
final class CreateReleaseCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
