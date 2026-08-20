<?php

namespace App\Commands;

use App\Support\MaintainerBanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Laravel\Prompts\Prompt;
use LaravelZero\Framework\Commands\Command;
use LogicException;
use Throwable;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

#[Signature('maintainer')]
#[Description('Open the Maintainer workflow menu')]
final class MaintainerCommand extends Command
{
    private const string BACK_SIGNAL = '__maintainer_back__';

    private const string SUBMENU_HINT = 'Press Ctrl+C to return to the main menu.';

    /**
     * @var array<string, string>
     */
    private const array SECTIONS = [
        'ci' => '1 - CI',
        'configuration' => '2 - Configuration',
        'deployment' => '3 - Deployment',
        'versioning' => '4 - Versioning',
        'exit' => '5 - Exit',
    ];

    /**
     * @var array<string, string>
     */
    private const array VERSIONING_WORKFLOWS = [
        'commit' => '1 - New Git commit',
        'diff:html' => '2 - View Git diff',
        'release:create' => '3 - New GitHub release',
    ];

    /**
     * @var array<string, string>
     */
    private const array CONFIGURATION_WORKFLOWS = [
        'config:publish' => '1 - Publish configuration files',
        'ssh:key' => '2 - SSH private key',
        'ssh:public' => '3 - SSH public key',
    ];

    /**
     * @var array<string, string>
     */
    private const array CI_WORKFLOWS = [
        'pint' => '1 - Pint',
        'rector' => '2 - Rector',
        'phpstan' => '3 - PHPStan',
        'pest' => '4 - Pest',
    ];

    /**
     * @var array<string, string>
     */
    private const array DEPLOYMENT_WORKFLOWS = [
        'deploy' => '1 - Deploy the project with Deployer',
    ];

    /**
     * Execute the console command.
     */
    public function handle(MaintainerBanner $banner): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('The maintainer command requires interactive input. Run release:create for a GitHub release or config:publish to publish configuration files.');

            return self::FAILURE;
        }

        while (true) {
            $this->renderScreen($banner, 'Main Menu');

            $section = select(
                label: 'What would you like to manage?',
                options: self::SECTIONS,
                default: 'ci',
                scroll: count(self::SECTIONS),
            );

            if ($section === 'exit') {
                return self::SUCCESS;
            }

            $exitCode = match ($section) {
                'versioning' => $this->runWorkflowMenu($banner, 'Main Menu › Versioning', 'Choose a versioning workflow', self::VERSIONING_WORKFLOWS),
                'configuration' => $this->runWorkflowMenu($banner, 'Main Menu › Configuration', 'Choose a configuration workflow', self::CONFIGURATION_WORKFLOWS),
                'ci' => $this->runCiMenu($banner),
                'deployment' => $this->runWorkflowMenu($banner, 'Main Menu › Deployment', 'Choose a deployment workflow', self::DEPLOYMENT_WORKFLOWS),
                default => throw new LogicException('The selected Maintainer section is not supported.'),
            };

            if ($exitCode !== null) {
                return $exitCode;
            }
        }
    }

    /**
     * @param  array<string, string>  $workflows
     */
    private function runWorkflowMenu(
        MaintainerBanner $banner,
        string $breadcrumb,
        string $label,
        array $workflows,
    ): ?int {
        $this->renderScreen($banner, $breadcrumb);
        Prompt::cancelUsing(static fn (): string => self::BACK_SIGNAL);

        try {
            $workflow = select(
                label: $label,
                options: $workflows,
                scroll: count($workflows),
                hint: self::SUBMENU_HINT,
            );
        } finally {
            Prompt::cancelUsing(null);
        }

        throw_unless(is_string($workflow), LogicException::class, 'The selected Maintainer workflow is not supported.');

        return $workflow === self::BACK_SIGNAL
            ? null
            : $this->call($workflow);
    }

    private function runCiMenu(MaintainerBanner $banner): ?int
    {
        $this->renderScreen($banner, 'Main Menu › CI');
        Prompt::cancelUsing(static fn (): array => [self::BACK_SIGNAL]);

        try {
            $workflows = multiselect(
                label: 'Choose CI tools to run',
                options: self::CI_WORKFLOWS,
                default: array_keys(self::CI_WORKFLOWS),
                scroll: count(self::CI_WORKFLOWS),
                required: true,
                hint: 'Use Space to select tools. Press Ctrl+C to return to the main menu.',
            );
        } finally {
            Prompt::cancelUsing(null);
        }

        if ($workflows === [self::BACK_SIGNAL]) {
            return null;
        }

        return $this->call('quality', ['--tool' => $workflows]);
    }

    private function renderScreen(MaintainerBanner $banner, string $breadcrumb): void
    {
        $this->clearTerminalWhenSupported();

        info($banner->render());
        note($breadcrumb, 'info');
    }

    private function clearTerminalWhenSupported(): void
    {
        if (! stream_isatty(STDOUT) || ! $this->output->isDecorated() || getenv('TERM') === 'dumb') {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && ! sapi_windows_vt100_support(STDOUT)) {
            return;
        }

        try {
            clear();
        } catch (Throwable) {
            // Terminal clearing is an optional presentation enhancement.
        }
    }
}
