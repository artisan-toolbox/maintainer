<?php

namespace App\Commands;

use App\Support\BrowserLauncher;
use App\Support\GitDiffGenerator;
use App\Support\HtmlDiffOutputFormat;
use App\Support\HtmlDiffRenderer;
use App\Support\MaintainerConfiguration;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

#[Signature('diff:html {base=HEAD : Base Git commit or reference} {target? : Target Git commit or reference; omit to compare with the working tree} {--output= : Path for the generated HTML file} {--no-open : Generate the HTML file without opening the browser}')]
#[Description('Generate an HTML Git diff and open it in the browser')]
final class CreateHtmlDiffCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        ProjectPath $projectPath,
        GitDiffGenerator $diffGenerator,
        HtmlDiffRenderer $renderer,
        BrowserLauncher $browser,
        Filesystem $files,
        MaintainerConfiguration $configuration,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $base = $this->argument('base');
        $target = $this->argument('target');

        try {
            $configuredOutputFormat = $configuration->get('git.diff.output_format');
            $outputFormat = is_string($configuredOutputFormat)
                ? HtmlDiffOutputFormat::tryFrom($configuredOutputFormat)
                : null;

            if ($outputFormat === null) {
                throw new RuntimeException('git.diff.output_format must be line_by_line or side_by_side.');
            }

            $diff = $diffGenerator->generate($projectRoot, $base, $target);
            $title = $target === null
                ? "Git diff: {$base} to working tree"
                : "Git diff: {$base} to {$target}";
            $html = $renderer->render($diff, $title, $outputFormat);
            $outputPath = $this->outputPath($projectRoot, $diff);

            $files->ensureDirectoryExists(dirname($outputPath));

            if ($files->put($outputPath, $html) === false) {
                $this->error("Unable to write the HTML diff to {$outputPath}.");

                return self::FAILURE;
            }

            $this->info("Generated HTML diff: {$outputPath}");

            if (! $this->option('no-open')) {
                $browser->open($outputPath);
                $this->info('Opened the HTML diff in the default browser.');
            }
        } catch (JsonException|RuntimeException $exception) {
            $this->error("Unable to generate the HTML diff: {$exception->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function outputPath(string $projectRoot, string $diff): string
    {
        $configuredPath = $this->option('output');

        if (is_string($configuredPath) && $configuredPath !== '') {
            $path = $this->isAbsolutePath($configuredPath)
                ? $configuredPath
                : $projectRoot.DIRECTORY_SEPARATOR.$configuredPath;

            return str_ends_with(strtolower($path), '.html') ? $path : $path.'.html';
        }

        $filename = sprintf(
            'diff-%s-%s.html',
            date('Ymd-His'),
            substr(hash('sha256', $diff), 0, 8),
        );

        return sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'maintainer'
            .DIRECTORY_SEPARATOR.'diffs'
            .DIRECTORY_SEPARATOR.$filename;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1;
    }
}
