<?php

use ArtisanToolbox\Maintainer\Deployer\GitTagSelector;
use ArtisanToolbox\Maintainer\Encryption\MaintainerEncrypterFactory;
use ArtisanToolbox\Maintainer\Maintainer;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintFix;
use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;

function distributedPharInternalPath(string $pharPath, string $entryPath): string
{
    $prefix = 'phar://'.str_replace('\\', '/', $pharPath).'/';
    $entryPath = str_replace('\\', '/', $entryPath);

    throw_unless(
        str_starts_with($entryPath, $prefix),
        RuntimeException::class,
        "Unable to resolve {$entryPath} relative to {$pharPath}.",
    );

    return substr($entryPath, strlen($prefix));
}

it('exports only explicit Maintainer integration surfaces to consumers', function () {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['autoload']['psr-4'])
        ->toBe([
            'ArtisanToolbox\\Maintainer\\Deployer\\' => 'app/Deployer/',
            'ArtisanToolbox\\Maintainer\\Encryption\\' => 'app/Encryption/',
            'ArtisanToolbox\\Maintainer\\Quality\\Contracts\\' => 'app/Quality/Contracts/',
            'ArtisanToolbox\\Maintainer\\Ssh\\' => 'app/Ssh/',
            'ArtisanToolbox\\Maintainer\\Versionable\\Contracts\\' => 'app/Versionable/Contracts/',
        ])
        ->and($manifest['autoload']['classmap'])->toBe([
            'app/Maintainer.php',
        ])
        ->and($manifest['autoload']['files'])->toBe([
            'app/Support/client_helpers.php',
        ])
        ->and(class_exists(Maintainer::class))->toBeTrue()
        ->and(class_exists(GitTagSelector::class))->toBeTrue()
        ->and(class_exists(MaintainerEncrypterFactory::class))->toBeTrue()
        ->and(interface_exists(RunsPintFix::class))->toBeTrue()
        ->and(class_exists(MaintainerSshKeys::class))->toBeTrue()
        ->and(interface_exists(Versionable::class))->toBeTrue()
        ->and(function_exists('maintainer_ssh_key'))->toBeTrue()
        ->and(function_exists('maintainer_ssh_public_key'))->toBeTrue()
        ->and($manifest['autoload-dev']['psr-4'])
        ->toHaveKeys([
            'App\\Ai\\',
            'App\\Commands\\',
            'App\\Foundation\\',
            'App\\Providers\\',
            'App\\Support\\',
            'Database\\Factories\\',
            'Database\\Seeders\\',
            'Tests\\',
        ])
        ->not->toHaveKey('App\\');
});

it('packages configuration and unmodified publishing templates in the PHAR', function () {
    $boxManifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/box.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $packagedConfiguration = array_values(array_filter(
        $boxManifest['files'],
        fn (string $path): bool => str_starts_with($path, 'config/'),
    ));

    expect($boxManifest['directories'])->not->toContain('config')
        ->not->toContain('resources')
        ->and($packagedConfiguration)->toBe([
            'config/ai.php',
            'config/app.php',
            'config/commands.php',
            'config/maintainer.php',
            'config/maintainer_secrets.php',
        ])
        ->and($boxManifest['directories-bin'])->toContain('resources')
        ->and(dirname(__DIR__, 2).'/config/maintainer.php')->toBeFile()
        ->and(dirname(__DIR__, 2).'/config/maintainer_secrets.php')->toBeFile();
});

it('normalizes distributed PHAR entry paths across operating systems', function (
    string $pharPath,
    string $entryPath,
) {
    expect(distributedPharInternalPath($pharPath, $entryPath))
        ->toBe('vendor/package/file.php');
})->with([
    'POSIX paths' => [
        '/tmp/maintainer.phar',
        'phar:///tmp/maintainer.phar/vendor/package/file.php',
    ],
    'Windows archive with normalized entry path' => [
        'C:\\Users\\runneradmin\\AppData\\Local\\Temp\\maintainer.phar',
        'phar://C:/Users/runneradmin/AppData/Local/Temp/maintainer.phar/vendor/package/file.php',
    ],
]);

it('excludes local configuration and credential signatures from the distributed PHAR', function () {
    $projectRoot = dirname(__DIR__, 2);
    $temporaryFile = tempnam(sys_get_temp_dir(), 'maintainer-phar-');
    $pharPath = $temporaryFile.'.phar';

    copy($projectRoot.'/builds/maintainer', $pharPath);

    try {
        $phar = new Phar($pharPath);

        expect(isset($phar['config/dev_maintainer.php']))->toBeFalse()
            ->and(isset($phar['config/dev_maintainer_secrets.php']))->toBeFalse();

        $credentialPatterns = [
            'OpenAI API key' => '/sk-(?:proj-)?[A-Za-z0-9_-]{20,}/',
            'AWS access key' => '/(?:AKIA|ASIA)[0-9A-Z]{16}/',
            'GitHub token' => '/(?:github_pat_[0-9A-Za-z_]{20,}|gh[pousr]_[0-9A-Za-z]{30,})/',
            'Google API key' => '/AIza[0-9A-Za-z_-]{35}/',
            'GitLab token' => '/glpat-[0-9A-Za-z_-]{20,}/',
            'Slack token' => '/xox[baprs]-[0-9A-Za-z-]{10,}/',
            'npm token' => '/npm_[A-Za-z0-9]{20,}/',
            'private key' => '/-----BEGIN (?:[A-Z0-9 ]+ )?PRIVATE KEY-----/',
        ];
        $violations = [];

        foreach (new RecursiveIteratorIterator($phar) as $file) {
            $internalPath = distributedPharInternalPath($pharPath, $file->getPathname());

            if (str_starts_with($internalPath, 'vendor/')) {
                continue;
            }

            $contents = $file->getContent();

            foreach ($credentialPatterns as $label => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = $internalPath.' ('.$label.')';
                }
            }
        }

        expect($violations)->toBeEmpty(
            'The distributed PHAR contains potential credentials: '.implode(', ', $violations),
        );
    } finally {
        @unlink($pharPath);
        @unlink($temporaryFile);
    }
});
