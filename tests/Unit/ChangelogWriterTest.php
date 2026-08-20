<?php

use App\Support\Ai\ChangelogEntry;
use App\Support\Release\ChangelogWriter;
use Illuminate\Filesystem\Filesystem;

it('creates and prepends detailed grouped changelog releases', function () {
    $files = new Filesystem;
    $directory = temporaryTestDirectory('maintainer-changelog-');
    $files->makeDirectory($directory);
    $writer = new ChangelogWriter($files);

    try {
        $writer->write($directory, '1.1.0', [
            new ChangelogEntry('feat', 'abc1234', 'Add release automation', 'Creates and publishes complete GitHub releases.'),
            new ChangelogEntry('fix', 'def5678', 'Restore failed preparations', 'Returns the worktree to its original clean state.'),
        ]);
        $writer->write($directory, '1.1.1', [
            new ChangelogEntry('docs', 'fed4321', 'Document release configuration', 'Explains every AI provider used by the workflow.'),
        ]);

        $contents = $files->get($directory.'/CHANGELOG.md');

        expect($contents)
            ->toStartWith("# Changelog\n\n## [1.1.1]")
            ->toContain('### Documentation')
            ->toContain('### Features')
            ->toContain('### Fixes')
            ->toContain('Creates and publishes complete GitHub releases.')
            ->and(strpos($contents, '## [1.1.1]'))->toBeLessThan(strpos($contents, '## [1.1.0]'));
    } finally {
        $files->deleteDirectory($directory);
    }
});
