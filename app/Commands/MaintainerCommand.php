<?php

namespace App\Commands;

use App\Support\MaintainerBanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use LogicException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

#[Signature('maintainer')]
#[Description('Open the Maintainer workflow menu')]
final class MaintainerCommand extends Command
{
    /**
     * @var array<string, string>
     */
    private const array WORKFLOWS = [
        'quality' => 'Run Pint, Rector, and PHPStan',
        'release:create' => 'Create a new GitHub release',
        'init' => 'Create the Maintainer configuration file',
        'diff:html' => 'View a Git diff in the browser',
    ];

    /**
     * Execute the console command.
     */
    public function handle(MaintainerBanner $banner): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('The maintainer command requires interactive input. Run release:create for a GitHub release or init to create the configuration file.');

            return self::FAILURE;
        }

        info($banner->render());

        $command = select(
            label: 'Which workflow would you like to run?',
            options: self::WORKFLOWS,
            default: 'release:create',
        );

        return match ($command) {
            'quality' => $this->call('quality'),
            'release:create' => $this->call('release:create'),
            'init' => $this->call('init'),
            'diff:html' => $this->call('diff:html'),
            default => throw new LogicException('The selected Maintainer workflow is not supported.'),
        };
    }
}
