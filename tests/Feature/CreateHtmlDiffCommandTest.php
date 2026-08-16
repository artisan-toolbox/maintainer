<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * @param  Closure(string, Filesystem): void  $callback
 */
function withinTemporaryGitDiffProject(Closure $callback): void
{
    $files = new Filesystem;
    $originalWorkingDirectory = getcwd();
    $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-diff-'.Str::uuid();
    $hadComposerAutoloadPath = array_key_exists('_composer_autoload_path', $GLOBALS);
    $originalComposerAutoloadPath = $GLOBALS['_composer_autoload_path'] ?? null;

    $files->makeDirectory($temporaryDirectory.'/vendor', recursive: true);
    $files->put($temporaryDirectory.'/composer.json', "{}\n");
    $files->put($temporaryDirectory.'/vendor/autoload.php', "<?php\n");
    $files->put($temporaryDirectory.'/example.txt', "before\n");

    foreach ([
        ['init'],
        ['config', 'user.name', 'Maintainer Tests'],
        ['config', 'user.email', 'maintainer@example.com'],
        ['config', 'core.autocrlf', 'false'],
        ['add', 'example.txt'],
        ['commit', '-m', 'Create fixture'],
    ] as $arguments) {
        (new Process(['git', ...$arguments], $temporaryDirectory))->mustRun();
    }

    $GLOBALS['_composer_autoload_path'] = $temporaryDirectory.'/vendor/autoload.php';
    chdir($temporaryDirectory);

    try {
        $callback($temporaryDirectory, $files);
    } finally {
        chdir($originalWorkingDirectory);

        if ($hadComposerAutoloadPath) {
            $GLOBALS['_composer_autoload_path'] = $originalComposerAutoloadPath;
        } else {
            unset($GLOBALS['_composer_autoload_path']);
        }

        $files->deleteDirectory($temporaryDirectory);
    }
}

it('generates an HTML diff for working tree changes', function () {
    withinTemporaryGitDiffProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/example.txt', "after\n");

        $this->artisan('diff:html', [
            '--output' => 'reports/working-tree-diff',
            '--no-open' => true,
        ])
            ->expectsOutputToContain('Generated HTML diff:')
            ->assertSuccessful();

        $outputPath = $directory.'/reports/working-tree-diff.html';

        expect($files->exists($outputPath))->toBeTrue()
            ->and($files->get($outputPath))
            ->toContain('Git diff: HEAD to working tree')
            ->toContain('diff2html@3.4.56')
            ->toContain("outputFormat: 'line-by-line'");
    });
});

it('uses the configured side-by-side output format', function () {
    withinTemporaryGitDiffProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "git": {
                    "diff": {
                        "output_format": "side_by_side"
                    }
                }
            }
            JSON.PHP_EOL);
        $files->put($directory.'/example.txt', "after\n");

        $this->artisan('diff:html', [
            '--output' => 'reports/side-by-side.html',
            '--no-open' => true,
        ])->assertSuccessful();

        expect($files->get($directory.'/reports/side-by-side.html'))
            ->toContain("outputFormat: 'side-by-side'");
    });
});

it('generates an HTML diff between two Git references', function () {
    withinTemporaryGitDiffProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/example.txt', "after\n");

        foreach ([
            ['add', 'example.txt'],
            ['commit', '-m', 'Update fixture'],
        ] as $arguments) {
            (new Process(['git', ...$arguments], $directory))->mustRun();
        }

        $this->artisan('diff:html', [
            'base' => 'HEAD~1',
            'target' => 'HEAD',
            '--output' => 'reports/commit-diff.html',
            '--no-open' => true,
        ])->assertSuccessful();

        expect($files->get($directory.'/reports/commit-diff.html'))
            ->toContain('Git diff: HEAD~1 to HEAD');
    });
});

it('fails when a Git reference is invalid', function () {
    withinTemporaryGitDiffProject(function (string $directory, Filesystem $files): void {
        $this->artisan('diff:html', [
            'base' => 'missing-reference',
            '--output' => 'reports/invalid.html',
            '--no-open' => true,
        ])
            ->expectsOutputToContain('Unable to generate the HTML diff:')
            ->assertFailed();

        expect($files->exists($directory.'/reports/invalid.html'))->toBeFalse();
    });
});

it('rejects an unsupported configured output format', function () {
    withinTemporaryGitDiffProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "git": {
                    "diff": {
                        "output_format": "unsupported"
                    }
                }
            }
            JSON.PHP_EOL);

        $this->artisan('diff:html', [
            '--output' => 'reports/unsupported.html',
            '--no-open' => true,
        ])
            ->expectsOutputToContain('git.diff.output_format must be line_by_line or side_by_side.')
            ->assertFailed();

        expect($files->exists($directory.'/reports/unsupported.html'))->toBeFalse();
    });
});
