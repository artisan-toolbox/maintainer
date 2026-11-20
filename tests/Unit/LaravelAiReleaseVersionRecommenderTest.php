<?php

use App\Ai\Agents\ReleaseVersionAgent;
use App\Support\Ai\LaravelAiReleaseVersionRecommender;
use App\Support\Release\ReleaseVersionOptions;
use App\Support\Release\SemanticVersionNumber;
use Illuminate\Filesystem\Filesystem;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @param array<string, string> $changes */
function withReleaseVersionDiff(array $changes, Closure $check, string $projectType = 'library'): void
{
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($changes, $check, $projectType): void {
        $files->put($directory.'/composer.json', json_encode(['type' => $projectType], JSON_THROW_ON_ERROR));
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['local_feedback_locale' => 'pt_BR', 'project_locale' => 'de'],
        ]);

        foreach ([
            ['init', '--initial-branch=2.x'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
            ['add', '.'],
            ['commit', '-m', 'Initial release'],
            ['tag', '2.3.4'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $directory)->mustRun();
        }

        foreach ($changes as $path => $content) {
            $files->ensureDirectoryExists(dirname($directory.'/'.$path));
            $files->put($directory.'/'.$path, $content."\n");
        }
        new Process(['git', 'add', '.'], $directory)->mustRun();

        $latest = new SemanticVersionNumber(2, 3, 4);
        $options = (new ReleaseVersionOptions)->forMajor(2, $latest);
        $check(fn () => resolve(LaravelAiReleaseVersionRecommender::class)->recommend('openai', $directory, $latest, $options));
    });
}

/** @return array{recommended_version: string, reasons: array<string, string>, public_change_evidence: string} */
function versionReportFixture(string $version = '2.3.5', string $evidence = ''): array
{
    return [
        'recommended_version' => $version,
        'reasons' => [
            '2.3.5' => 'The patch option fits maintenance.',
            '2.4.0' => 'The minor option requires a public capability.',
            '2.4.0-alpha.1' => 'Alpha starts a preview cycle.',
            '2.4.0-beta.1' => 'Beta starts a stabilization cycle.',
        ],
        'public_change_evidence' => $evidence,
    ];
}

it('compares every actual version in the local feedback language using the configured provider model', function () {
    withReleaseVersionDiff(['src/Export.php' => 'public function exportCsv(): string'], function (Closure $recommend): void {
        $fixture = versionReportFixture('2.4.0', '+public function exportCsv(): string');
        $fixture['reasons'] = [
            '2.3.5' => 'Um patch não representa a nova exportação pública.',
            '2.4.0' => 'A versão inclui exportação CSV para os consumidores.',
            '2.4.0-alpha.1' => 'A mudança não propõe uma prévia experimental.',
            '2.4.0-beta.1' => 'O diff não indica um ciclo de estabilização.',
        ];
        ReleaseVersionAgent::fake([$fixture]);

        $report = $recommend();

        expect($report->version)->toBe('2.4.0')->and($report->reasons)->toBe($fixture['reasons']);
        ReleaseVersionAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $schema = $prompt->agent->schema(new JsonSchemaTypeFactory);
            $options = $schema['reasons']->toArray()['properties'];

            return array_keys($options) === ['2.3.5', '2.4.0', '2.4.0-alpha.1', '2.4.0-beta.1']
                && $schema['recommended_version']->toArray()['enum'] === ['2.3.5', '2.4.0', null]
                && $prompt->model === 'gpt-6.2-sol'
                && str_contains($prompt->agent->instructions(), 'locale pt_BR')
                && str_contains($prompt->prompt, 'src/Export.php');
        });
    });
});

it('only offers patch for repository guidance, tests and environment templates', function (string $path) {
    withReleaseVersionDiff([$path => 'Add export coverage'], function (Closure $recommend): void {
        ReleaseVersionAgent::fake([versionReportFixture()]);
        expect($recommend()->version)->toBe('2.3.5');
        ReleaseVersionAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->schema(new JsonSchemaTypeFactory)['recommended_version']->toArray()['enum'] === ['2.3.5']);
    });
})->with(['README.md', 'CHANGELOG.md', 'tests/ExportTest.php', '.env.example', 'docs/changelog/changes.md', '.github/workflows/check.yml']);

it('rejects incomplete, unsupported and ungrounded recommendations', function (Closure $alter) {
    withReleaseVersionDiff(['src/Export.php' => 'public function exportCsv(): string'], function (Closure $recommend) use ($alter): void {
        ReleaseVersionAgent::fake([$alter(versionReportFixture())]);
        expect($recommend)->toThrow(RuntimeException::class);
    });
})->with([
    'unavailable major' => fn (array $data) => array_replace($data, ['recommended_version' => '3.0.0']),
    'unavailable stable default' => fn (array $data) => array_replace($data, ['recommended_version' => '2.4.0-beta.1']),
    'extra reason' => fn (array $data) => array_replace_recursive($data, ['reasons' => ['3.0.0' => 'Extra.']]),
    'missing option' => fn (array $data) => array_replace($data, ['reasons' => ['2.3.5' => 'Only one.']]),
    'blank reason' => fn (array $data) => array_replace_recursive($data, ['reasons' => ['2.4.0' => ' ']]),
    'terminal control sequence' => fn (array $data) => array_replace_recursive($data, ['reasons' => ['2.3.5' => "Patch\033[2J"]]),
    'missing minor evidence' => fn (array $data) => array_replace($data, ['recommended_version' => '2.4.0']),
    'invented minor evidence' => fn () => versionReportFixture('2.4.0', '+public function invent(): string'),
    'diff metadata' => fn () => versionReportFixture('2.4.0', '+++ b/src/Export.php'),
]);

it('retains a grounded public change among maintenance fragments', function () {
    withReleaseVersionDiff([
        '.github/workflows/check.yml' => 'run: composer test',
        'src/Export.php' => 'public function exportCsv(): string',
    ], function (Closure $recommend): void {
        ReleaseVersionAgent::fake([
            versionReportFixture(),
            versionReportFixture('2.4.0', '+public function exportCsv(): string'),
        ]);
        expect($recommend()->version)->toBe('2.4.0');
    });
});

it('recognizes a shipped reusable workflow input as a public package capability', function () {
    withReleaseVersionDiff(['.github/workflows/release.yml' => "on:\n  workflow_call:\n    inputs:\n      dry-run:\n        type: boolean"], function (Closure $recommend): void {
        ReleaseVersionAgent::fake([versionReportFixture('2.4.0', '+      dry-run:')]);
        expect($recommend()->version)->toBe('2.4.0');
    });
});

it('skips analysis when only filtered application development files changed', function () {
    withReleaseVersionDiff(['.ai/rules/release.md' => 'Experimental tool.'], function (Closure $recommend): void {
        ReleaseVersionAgent::fake()->preventStrayPrompts();
        expect($recommend())->toBeNull();
        ReleaseVersionAgent::assertNeverPrompted();
    }, projectType: 'project');
});

it('includes application runtime files while excluding its development guidance', function () {
    withReleaseVersionDiff([
        '.mcp.json' => '{}',
        'app/Feature.php' => 'public function exportCsv(): string',
    ], function (Closure $recommend): void {
        ReleaseVersionAgent::fake([versionReportFixture('2.4.0', '+public function exportCsv(): string')])->preventStrayPrompts();
        expect($recommend()->version)->toBe('2.4.0');
        ReleaseVersionAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Final Laravel application.')
            && str_contains($prompt->prompt, 'app/Feature.php') && ! str_contains($prompt->prompt, '.mcp.json'));
    }, projectType: 'project');
});

it('includes distributed package AI integrations when assessing the public API', function () {
    withReleaseVersionDiff(['resources/boost/skills/exports/SKILL.md' => 'Call the CSV export MCP tool.'], function (Closure $recommend): void {
        ReleaseVersionAgent::fake([versionReportFixture('2.4.0', '+Call the CSV export MCP tool.')]);
        expect($recommend()->version)->toBe('2.4.0');
        ReleaseVersionAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Reusable Laravel package.'));
    });
});
