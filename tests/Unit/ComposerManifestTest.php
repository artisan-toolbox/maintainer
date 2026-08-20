<?php

use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;

it('exports only the public Maintainer namespace to consumers', function () {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['autoload']['psr-4'])
        ->toBe([
            'ArtisanToolbox\\Maintainer\\' => 'app/',
        ])
        ->and($manifest['autoload']['files'])->toBe([
            'app/Support/client_helpers.php',
        ])
        ->and(class_exists(MaintainerSshKeys::class))->toBeTrue()
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
        $pharPrefix = 'phar://'.$pharPath.'/';

        foreach (new RecursiveIteratorIterator($phar) as $file) {
            $internalPath = str_replace($pharPrefix, '', $file->getPathname());

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

        expect($violations)->toBeEmpty();
    } finally {
        @unlink($pharPath);
        @unlink($temporaryFile);
    }
});
