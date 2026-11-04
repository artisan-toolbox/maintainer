<?php

namespace App\Support\Release;

use ArtisanToolbox\Maintainer\Versionable\Readme\Badge;
use ArtisanToolbox\Maintainer\Versionable\Readme\Head;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

use function Illuminate\Filesystem\join_paths;

final readonly class ReadmeHeadWriter
{
    private const string START = '<!-- MAINTAINER:README_HEAD:START -->';

    private const string END = '<!-- MAINTAINER:README_HEAD:END -->';

    public function __construct(private Filesystem $files) {}

    public function update(string $projectRoot, Head $head, ?string $version): bool
    {
        $path = join_paths($projectRoot, 'README.md');
        $contents = $this->files->get($path);
        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $sections = $this->render($head, $version, $lineEnding);
        $region = $sections === ''
            ? self::START.$lineEnding.self::END
            : self::START.$lineEnding.$sections.$lineEnding.self::END;
        $markers = $this->markers($contents);

        if ($markers === []) {
            if ($sections === '') {
                return false;
            }

            $updated = $region.$lineEnding.($contents === '' ? '' : $lineEnding.$contents);
        } else {
            throw_if(array_column($markers, 0) !== [self::START, self::END], RuntimeException::class, 'README.md must contain one matching pair of Maintainer README head markers.');

            $start = $markers[0][1];
            $end = $markers[1][1] + strlen(self::END);
            $updated = substr_replace($contents, $region, $start, $end - $start);
        }

        if ($updated === $contents) {
            return false;
        }

        throw_if($this->files->put($path, $updated) !== strlen($updated), RuntimeException::class, 'Unable to write the managed head to README.md.');

        return true;
    }

    /** @return list<array{string, int}> */
    private function markers(string $contents): array
    {
        $pattern = '~^(?:'.preg_quote(self::START, '~').'|'.preg_quote(self::END, '~').')(?=\r?$)~m';
        preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);

        return array_values(array_filter(
            $matches[0],
            fn (array $marker): bool => ! $this->insideFence($contents, $marker[1]),
        ));
    }

    private function render(Head $head, ?string $version, string $lineEnding): string
    {
        $sections = [];
        $logo = $head->logo();
        $title = $head->title();
        $description = $head->description();
        $badges = $head->badges();

        $heading = [];

        if ($logo !== null) {
            $heading[] = '    <img'.$this->attributes([
                'src' => $logo['src'],
                'alt' => $logo['alt'],
                'width' => (string) $logo['width'],
            ]).'>';
        }

        if ($title !== null && trim($title) !== '') {
            $heading[] = '    <h1>'.$this->escape($title).'</h1>';
        }

        if ($heading !== []) {
            $sections[] = implode($lineEnding, [
                '<div id="maintainer-title" align="center">',
                ...$heading,
                '</div>',
            ]);
        }

        if ($badges !== []) {
            $sections[] = implode($lineEnding, [
                '<p id="maintainer-badges" align="center">',
                ...array_map(fn (Badge $badge): string => $this->renderBadge($badge, $version), $badges),
                '</p>',
            ]);
        }

        if ($description !== null && trim($description) !== '') {
            $sections[] = implode($lineEnding, [
                '<p id="maintainer-description" align="center">',
                '    '.$this->escape($description),
                '</p>',
            ]);
        }

        return implode($lineEnding.$lineEnding, $sections);
    }

    private function renderBadge(Badge $badge, ?string $version): string
    {
        $anchor = $this->attributes(['href' => $badge->link(), 'title' => $badge->title(), 'id' => $badge->id()]);
        $image = $this->attributes(['src' => $badge->imgSrc($version), 'alt' => $badge->imgAlt()]);

        return "<a{$anchor}><img{$image}></a>";
    }

    /** @param array<string, string|null> $attributes */
    private function attributes(array $attributes): string
    {
        $result = '';

        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $result .= ' '.$name.'="'.$this->escape($value).'"';
            }
        }

        return $result;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function insideFence(string $contents, int $offset): bool
    {
        $prefix = substr($contents, 0, $offset);
        preg_match_all('/^ {0,3}(`{3,}|~{3,})([^\r\n]*)\r?$/m', $prefix, $matches, PREG_SET_ORDER);
        $openFence = null;

        foreach ($matches as [, $delimiter, $info]) {
            if ($openFence === null) {
                if ($delimiter[0] === '~' || ! str_contains($info, '`')) {
                    $openFence = $delimiter;
                }

                continue;
            }

            if ($delimiter[0] === $openFence[0] && strlen($delimiter) >= strlen($openFence) && trim($info, " \t") === '') {
                $openFence = null;
            }
        }

        return $openFence !== null;
    }
}
