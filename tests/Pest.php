<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/**
 * @return array<string, mixed>
 */
function defaultMaintainerConfigurationFixture(): array
{
    return [
        'ai' => [
            'providers' => [
                'commit_message' => 'openai',
                'release_type_suggestion' => 'openai',
                'release_notes' => 'openai',
                'release_changelog_update' => 'openai',
            ],
        ],
        'git' => [
            'diff' => [
                'output_format' => 'line_by_line',
            ],
        ],
        'quality' => [
            'phpstan' => [
                'memory_limit' => '2G',
            ],
        ],
    ];
}

function withinTemporaryProject(
    Closure $callback,
    string $workingDirectory = '.',
    bool $exposeComposerProxy = true,
): void {
    $files = new Filesystem;
    $originalWorkingDirectory = getcwd();
    $temporaryDirectory = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR.'maintainer-'
        .Str::uuid();
    $hadComposerAutoloadPath = array_key_exists('_composer_autoload_path', $GLOBALS);
    $originalComposerAutoloadPath = $GLOBALS['_composer_autoload_path'] ?? null;

    $files->makeDirectory($temporaryDirectory.'/vendor/bin', recursive: true);
    $files->put($temporaryDirectory.'/composer.json', "{}\n");
    $files->put($temporaryDirectory.'/vendor/autoload.php', "<?php\n");

    if ($workingDirectory !== '.') {
        $files->makeDirectory($temporaryDirectory.'/'.$workingDirectory, recursive: true, force: true);
    }

    if ($exposeComposerProxy) {
        $GLOBALS['_composer_autoload_path'] = $temporaryDirectory.'/vendor/autoload.php';
    } else {
        unset($GLOBALS['_composer_autoload_path']);
    }

    chdir($temporaryDirectory.'/'.$workingDirectory);

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
