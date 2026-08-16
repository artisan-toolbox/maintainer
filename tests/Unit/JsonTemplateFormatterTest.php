<?php

use App\Support\Configuration\JsonTemplateFormatter;

it('pretty prints compacted JSON templates before publication', function () {
    $formatted = (new JsonTemplateFormatter)->format(
        '{"ai":{"providers":{"commit_message":"openai"}},"url":"https:\/\/example.test\/v1"}',
        'test configuration',
    );

    expect($formatted)->toBe(<<<'JSON'
        {
            "ai": {
                "providers": {
                    "commit_message": "openai"
                }
            },
            "url": "https://example.test/v1"
        }

        JSON);
});

it('rejects invalid or non-object JSON templates', function (string $contents, string $message) {
    expect(fn () => (new JsonTemplateFormatter)->format($contents, 'test configuration'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'invalid JSON' => ['{invalid', 'contains invalid JSON'],
    'JSON array' => ['[]', 'must contain a JSON object'],
]);
