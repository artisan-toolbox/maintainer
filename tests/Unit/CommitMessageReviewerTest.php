<?php

use App\Support\Git\CommitMessageReviewer;
use Laravel\Prompts\TextareaPrompt;

it('prefills generated commit messages and returns the edited result', function () {
    $reviewer = new CommitMessageReviewer(function (TextareaPrompt $prompt): string {
        expect($prompt->label)->toBe('Review the generated commit message')
            ->and($prompt->default)->toBe('feat: generated message');

        assert($prompt->validate instanceof Closure);

        expect(($prompt->validate)(''))->toBe('A commit message is required.')
            ->and(($prompt->validate)('feat: valid message'))->toBeNull();

        return "  feat: edited message\n";
    });

    expect($reviewer->review('feat: generated message', true))
        ->toBe('feat: edited message');
});

it('opens an empty editor for manual commit messages', function () {
    $reviewer = new CommitMessageReviewer(function (TextareaPrompt $prompt): string {
        expect($prompt->label)->toBe('Write the commit message')
            ->and($prompt->default)->toBe('');

        return 'docs: write the message manually';
    });

    expect($reviewer->review('', false))->toBe('docs: write the message manually');
});
