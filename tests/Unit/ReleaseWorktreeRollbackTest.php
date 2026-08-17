<?php

use App\Support\Release\ReleaseWorktreeRollback;
use Tests\Fakes\FakeReleaseGitRepository;

it('rolls back an armed release only once before push', function () {
    $git = new FakeReleaseGitRepository;
    $rollback = new ReleaseWorktreeRollback;
    $rollback->arm($git, '/project', 'baseline-head');

    expect($rollback->isArmed())->toBeTrue()
        ->and($rollback->rollback())->toBeTrue()
        ->and($git->rolledBack)->toBeTrue()
        ->and($rollback->rollback())->toBeFalse();
});

it('does not roll back after the release commit has been pushed', function () {
    $git = new FakeReleaseGitRepository;
    $rollback = new ReleaseWorktreeRollback;
    $rollback->arm($git, '/project', 'baseline-head');
    $rollback->markPushed();

    expect($rollback->wasPushed())->toBeTrue()
        ->and($rollback->rollback())->toBeFalse()
        ->and($git->rolledBack)->toBeFalse();
});
