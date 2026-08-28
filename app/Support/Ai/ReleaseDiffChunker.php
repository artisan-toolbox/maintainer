<?php

namespace App\Support\Ai;

final readonly class ReleaseDiffChunker
{
    private const int DEFAULT_MAX_CHARACTERS = 24_000;

    private const int DEFAULT_MAX_CHUNKS = 16;

    public function __construct(
        private int $maxCharacters = self::DEFAULT_MAX_CHARACTERS,
        private int $maxChunks = self::DEFAULT_MAX_CHUNKS,
    ) {}

    public function chunk(string $diff, bool $omitDevelopmentAiFiles = false): ReleaseDiffContext
    {
        $sections = preg_split('/(?=^diff --git )/m', trim($diff), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $omittedFiles = [];
        $truncated = false;

        foreach ($sections as $section) {
            $path = $this->path($section);

            if ($path !== null && $this->shouldOmit($path, $omitDevelopmentAiFiles)) {
                $omittedFiles[] = $path;

                continue;
            }

            foreach ($this->split($section) as $part) {
                if (count($chunks) >= $this->maxChunks) {
                    $truncated = true;

                    break 2;
                }

                $chunks[] = $part;
            }
        }

        $hasAnalyzableChanges = $chunks !== [];

        if (! $hasAnalyzableChanges) {
            $chunks[] = 'No textual source diff remains after generated files and dependency lock files were omitted.';
        }

        return new ReleaseDiffContext(
            $chunks,
            array_values(array_unique($omittedFiles)),
            $truncated,
            $hasAnalyzableChanges,
        );
    }

    private function path(string $section): ?string
    {
        if (preg_match('/^diff --git (?:"?a\/.*?) (?:"?b\/([^"\r\n]+)"?)/', $section, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function shouldOmit(string $path, bool $omitDevelopmentAiFiles): bool
    {
        if (preg_match(
            '/(?:^|\/)(?:vendor|node_modules|builds|dist|coverage)\/|(?:^|\/)(?:composer\.lock|package-lock\.json|pnpm-lock\.yaml|yarn\.lock)$|\.min\.(?:css|js)$/i',
            $path,
        ) === 1) {
            return true;
        }

        return $omitDevelopmentAiFiles && preg_match(
            '/^(?:(?:AGENTS|CLAUDE|GEMINI)\.md|boost\.json|\.mcp\.json|mcp\.json|\.cursorrules|\.windsurfrules|(?:\.ai|\.agents|\.claude|\.codex|\.cursor|\.gemini|\.junie|\.windsurf)\/|\.github\/(?:copilot-instructions\.md|instructions\/|prompts\/)|\.vscode\/mcp\.json)/i',
            $path,
        ) === 1;
    }

    /** @return list<string> */
    private function split(string $section): array
    {
        $lines = preg_split('/(?<=\n)/', $section, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        $part = '';

        foreach ($lines as $line) {
            while (strlen($line) > $this->maxCharacters) {
                if ($part !== '') {
                    $parts[] = $part;
                    $part = '';
                }

                $parts[] = substr($line, 0, $this->maxCharacters);
                $line = substr($line, $this->maxCharacters);
            }

            if ($part !== '' && strlen($part.$line) > $this->maxCharacters) {
                $parts[] = $part;
                $part = '';
            }

            $part .= $line;
        }

        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
    }
}
