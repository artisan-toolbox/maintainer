<?php

use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPestCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPhpStanCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintCheck;

return [
    'ai' => [
        'providers' => [
            'commit_message' => env('MAINTAINER_AI_COMMIT_MESSAGE_PROVIDER', 'openai'),
            'release_type_suggestion' => env('MAINTAINER_AI_RELEASE_TYPE_SUGGESTION_PROVIDER', 'openai'),
            'release_notes' => env('MAINTAINER_AI_RELEASE_NOTES_PROVIDER', 'openai'),
            'release_changelog_update' => env('MAINTAINER_AI_RELEASE_CHANGELOG_UPDATE_PROVIDER', 'openai'),
        ],
        'models' => [
            'commit_message' => env('MAINTAINER_AI_COMMIT_MESSAGE_MODEL'),
            'release_type_suggestion' => env('MAINTAINER_AI_RELEASE_TYPE_SUGGESTION_MODEL'),
            'release_notes' => env('MAINTAINER_AI_RELEASE_NOTES_MODEL'),
            'release_changelog_update' => env('MAINTAINER_AI_RELEASE_CHANGELOG_UPDATE_MODEL'),
        ],
        'local_feedback_locale' => env('MAINTAINER_AI_LOCAL_FEEDBACK_LOCALE'),
        'project_locale' => env('MAINTAINER_AI_PROJECT_LOCALE', 'en'),
    ],
    'git' => [
        'diff' => [
            'output_format' => 'line_by_line',
        ],
    ],
    'release' => [
        'workflow' => 'tests.yml',
    ],
    'quality' => [
        'test' => [
            RunsPintCheck::class,
            RunsPhpStanCheck::class,
            RunsPestCheck::class,
        ],
        'pest' => [
            'parallel' => env('MAINTAINER_PEST_PARALLEL', true),
            'processes' => 4,
        ],
        'phpstan' => [
            'memory_limit' => env('MAINTAINER_PHPSTAN_MEMORY_LIMIT', '2G'),
        ],
    ],
];
