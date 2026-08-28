<?php

use App\Support\Release\ReleaseTitleReviewer;
use Laravel\Prompts\TextareaPrompt;

it('prefills generated release titles and returns the edited result', function () {
    $reviewer = new ReleaseTitleReviewer(function (TextareaPrompt $prompt): string {
        expect($prompt->label)->toBe('Review the GitHub release title')
            ->and($prompt->default)->toBe('1.2.0 - Generated outcome');

        assert($prompt->validate instanceof Closure);

        expect(($prompt->validate)(''))->toBe('A release title is required.')
            ->and(($prompt->validate)('Edited without the tag'))->toContain('must use 1.2.0 - ')
            ->and(($prompt->validate)('1.2.0 - '))->toContain('must use 1.2.0 - ')
            ->and(($prompt->validate)("1.2.0 - First line\nSecond line"))->toContain('must be a single line')
            ->and(($prompt->validate)('1.2.0 - '.str_repeat('a', 101)))->toContain('no more than 100 characters')
            ->and(($prompt->validate)('1.2.0 - Edited outcome'))->toBeNull();

        return "  1.2.0 - Edited outcome\n";
    });

    expect($reviewer->review('1.2.0', '1.2.0 - Generated outcome'))
        ->toBe('1.2.0 - Edited outcome');
});
