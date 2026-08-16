<?php

namespace App\Commands;

use App\Support\MaintainerBanner;
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
     * @var array<string, string>
     */
    private const array WORKFLOWS = [
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
            $this->error('The maintainer command requires interactive input. Run release:create for a GitHub release or init to create the configuration file.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($banner->render());
        $this->newLine();

        $command = select(
            label: 'Which workflow would you like to run?',
            options: self::WORKFLOWS,
            default: 'release:create',
        );

        return match ($command) {
            'release:create' => $this->call('release:create'),
            'init' => $this->call('init'),
            'diff:html' => $this->call('diff:html'),
            default => throw new LogicException('The selected Maintainer workflow is not supported.'),
        };
    }
}
