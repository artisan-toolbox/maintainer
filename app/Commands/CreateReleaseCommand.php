<?php

namespace App\Commands;

use App\Support\GitWorkingTree;
use App\Support\ProjectPath;
use App\Support\VersionableImplementation;
use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

#[Signature('release:create')]
#[Description('Create a new GitHub release for the project')]
final class CreateReleaseCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        ProjectPath $projectPath,
        GitWorkingTree $workingTree,
        VersionableImplementation $versionableImplementation,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            if (! $workingTree->isClean($projectRoot)) {
                $this->components->error('The Git working tree is not clean. Commit or discard all staged, unstaged, and untracked changes before creating a GitHub release.');

                return self::FAILURE;
            }
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to verify the Git working tree: '.$exception->getMessage());

            return self::FAILURE;
        }

        try {
            $versionableClass = $versionableImplementation->find($projectRoot);

            if ($versionableClass === null) {
                $this->components->error(sprintf(
                    'No class directly in a production PSR-4 namespace implements %s.',
                    Versionable::class,
                ));

                return self::FAILURE;
            }

            if (! $versionableClass->hasVersionConstant) {
                $this->components->error(sprintf(
                    '%s must declare public const string VERSION.',
                    $versionableClass->name,
                ));

                return self::FAILURE;
            }
        } catch (RuntimeException $exception) {
            $this->components->error('Unable to inspect the project version class: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
