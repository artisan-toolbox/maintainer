<?php

use App\Support\Ai\AiReportLocale;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

it('uses the configured language or the effective terminal locale for the recommendation', function (?string $configured, string $system, string $expected) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($configured, $system, $expected) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['local_feedback_locale' => $configured],
        ]);
        $original = getenv('LC_ALL');
        putenv("LC_ALL={$system}");

        try {
            $locales = app()->makeWith(AiReportLocale::class, ['operatingSystem' => 'Linux']);
            expect($locales->forReleaseRecommendation())->toBe($expected)
                ->and($locales->forProjectArtifacts())->toBe('en');
        } finally {
            putenv($original === false ? 'LC_ALL' : "LC_ALL={$original}");
        }
    });
})->with([
    'explicit override' => ['pt_BR', 'en_US.UTF-8', 'pt_BR'],
    'POSIX locale with charset' => [null, 'es_AR.UTF-8', 'es_AR'],
    'English fallback for C locale' => [null, 'C.UTF-8', 'en'],
]);

it('uses the first preferred macOS language when the terminal supplies no language', function (?string $terminal, string $output, int $exitCode, string $expected) {
    withinTemporaryProject(function () use ($terminal, $output, $exitCode, $expected) {
        Process::fake(fn () => Process::result(output: $output, exitCode: $exitCode));
        $variables = ['LC_ALL', 'LC_MESSAGES', 'LANG'];
        $original = array_map(getenv(...), $variables);
        foreach ($variables as $variable) {
            putenv($variable);
        }
        if ($terminal !== null) {
            putenv("LC_ALL={$terminal}");
        }

        try {
            $locales = app()->makeWith(AiReportLocale::class, ['operatingSystem' => 'Darwin']);
            expect($locales->forReleaseRecommendation())->toBe($expected)
                ->and($locales->forProjectArtifacts())->toBe('en');
        } finally {
            foreach ($variables as $index => $variable) {
                putenv($original[$index] === false ? $variable : "{$variable}={$original[$index]}");
            }
        }
    });
})->with([
    'Portuguese with C.UTF-8' => ['C.UTF-8', "(\n    \"pt-BR\",\n    \"en-BR\"\n)\n", 0, 'pt_BR'],
    'language with script when the environment is unset' => [null, "(\n    \"zh-Hant-TW\"\n)\n", 0, 'zh_Hant_TW'],
    'explicit terminal language takes precedence' => ['es_AR.UTF-8', '("pt-BR")', 0, 'es_AR'],
    'English if macOS cannot read preferences' => ['POSIX', '', 1, 'en'],
]);

it('rejects a malformed explicitly configured report language', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['local_feedback_locale' => 'use any language'],
        ]);

        expect(fn () => resolve(AiReportLocale::class)->forReleaseRecommendation())
            ->toThrow(RuntimeException::class, 'ai.local_feedback_locale');
    });
});

it('keeps the local feedback and project languages independent', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['local_feedback_locale' => 'pt_BR', 'project_locale' => 'es_AR'],
        ]);
        $locales = resolve(AiReportLocale::class);

        expect($locales->forReleaseRecommendation())->toBe('pt_BR')
            ->and($locales->forProjectArtifacts())->toBe('es_AR');
    });
});
