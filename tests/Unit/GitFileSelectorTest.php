<?php

use App\Support\Git\GitChangedFile;
use App\Support\Git\GitFileSelector;
use Laravel\Prompts\MultiSearchPrompt;

it('preselects every changed file in the searchable prompt', function () {
    $files = [
        new GitChangedFile(' M', ' M app/First.php', ['app/First.php']),
        new GitChangedFile('??', '?? app/Second.php', ['app/Second.php']),
    ];
    $selector = new GitFileSelector(function (MultiSearchPrompt $prompt): array {
        expect($prompt->values)->toBe([
            'change-0' => ' M app/First.php',
            'change-1' => '?? app/Second.php',
        ])
            ->and(($prompt->options)('second'))->toBe([
                'change-1' => '?? app/Second.php',
            ]);

        return array_keys($prompt->values);
    });

    expect($selector->select($files))->toBe($files);
});
