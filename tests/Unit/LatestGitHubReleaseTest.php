<?php

use App\Support\Release\GitHubReleaseSource;
use App\Support\Release\LatestGitHubRelease;
use App\Support\Release\SemanticVersion;

it('finds the highest valid GitHub release for the branch major', function () {
    $source = new class implements GitHubReleaseSource
    {
        public function versions(string $projectRoot): array
        {
            return [
                'invalid',
                '2.0.0',
                '1.3.0-alpha.1',
                '1.2.9',
                '1.3.0-beta.2',
                '1.3.0-beta.1',
            ];
        }
    };

    $latest = new LatestGitHubRelease($source, new SemanticVersion)->forMajor('/project', 1);

    expect($latest?->value())->toBe('1.3.0-beta.2');
});

it('returns no release when GitHub has none for the branch major', function () {
    $source = new class implements GitHubReleaseSource
    {
        public function versions(string $projectRoot): array
        {
            return ['1.9.0', 'invalid'];
        }
    };

    $latest = new LatestGitHubRelease($source, new SemanticVersion)->forMajor('/project', 2);

    expect($latest)->toBeNull();
});
