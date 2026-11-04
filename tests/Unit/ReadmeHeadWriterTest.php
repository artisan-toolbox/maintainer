<?php

use App\Support\Release\ReadmeHeadWriter;
use ArtisanToolbox\Maintainer\Versionable\Readme\Badge;
use ArtisanToolbox\Maintainer\Versionable\Readme\Head;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('prepends the centered header and renders badges in insertion order', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $files->put($readme, "## Installation\n\nKeep these instructions.\n");
        $writer = new ReadmeHeadWriter($files);
        $head = new Head;

        expect($head
            ->withTitle('Example & Tools')
            ->withLogo(src: 'assets/logo.svg?theme=light&size=large', alt: 'Example "logo"', width: 96)
            ->withDescription('Use <examples> here.')
            ->addBadge(Badge::make('VERSION', 'version.svg', 'Release "version"', 'Release', 'release'))
            ->addBadge(Badge::make('https://example.com', 'tests.svg?branch=1.x&label=Tests'))
        )->toBe($head);

        expect($writer->update($directory, $head, null))->toBeTrue()
            ->and($files->get($readme))->toBe(<<<'HTML'
<!-- MAINTAINER:README_HEAD:START -->
<div id="maintainer-title" align="center">
    <img src="assets/logo.svg?theme=light&amp;size=large" alt="Example &quot;logo&quot;" width="96">
    <h1>Example &amp; Tools</h1>
</div>

<p id="maintainer-badges" align="center">
<a href="VERSION" title="Release" id="release"><img src="version.svg" alt="Release &quot;version&quot;"></a>
<a href="https://example.com"><img src="tests.svg?branch=1.x&amp;label=Tests"></a>
</p>

<p id="maintainer-description" align="center">
    Use &lt;examples&gt; here.
</p>
<!-- MAINTAINER:README_HEAD:END -->

## Installation

Keep these instructions.

HTML)
            ->and($writer->update($directory, $head, null))->toBeFalse();
    });
});

it('renders description lines as centered paragraphs with inline Markdown', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $files->put($readme, "# Example\n");
        $writer = new ReadmeHeadWriter($files);
        $head = (new Head)->withDescription('Old description');

        expect($head->description())->toBe('Old description')
            ->and($head->withDescription([' ', 'Use **Laravel** and `artisan`.']))->toBe($head)
            ->and($head->appendDescriptionLine('Read the [guide](https://example.com).'))->toBe($head)
            ->and($writer->update($directory, $head, null))->toBeTrue()
            ->and($files->get($readme))->toContain(<<<'HTML'
<p id="maintainer-description" align="center">
    Use <strong>Laravel</strong> and <code>artisan</code>.
</p>

<p align="center">
    Read the <a href="https://example.com">guide</a>.
</p>
HTML)
            ->not->toContain('Old description')
            ->and($writer->update($directory, $head, null))->toBeFalse();

        $head->withDescription(null);

        expect($head->description())->toBeNull()
            ->and($writer->update($directory, $head, null))->toBeTrue()
            ->and($files->get($readme))->not->toContain('<p');
    });
});

it('keeps Markdown block syntax inside the description paragraph after a blank line', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $files->put($readme, "# Example\r\n");
        $head = (new Head)->withDescription("Line one.\r\n\r\n# Line two.");

        expect(new ReadmeHeadWriter($files)->update($directory, $head, null))->toBeTrue()
            ->and(Str::markdown($files->get($readme)))->toContain('Line one. # Line two.')
            ->not->toContain('<h1>Line two.</h1>');
    });
});

it('renders a standalone logo at the default display width', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $files->put($readme, "## Installation\n");
        $head = (new Head)->withLogo('https://example.com/logo.svg', '');

        expect(new ReadmeHeadWriter($files)->update($directory, $head, null))->toBeTrue()
            ->and($files->get($readme))->toContain(<<<'HTML'
<div id="maintainer-title" align="center">
    <img src="https://example.com/logo.svg" alt="" width="160">
</div>
HTML)
            ->not->toContain('<h1>');
    });
});

it('rejects invalid logo settings', function (string $src, int $width, string $message) {
    expect(fn () => (new Head)->withLogo($src, 'Example', $width))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank source' => [' ', 160, 'The logo source cannot be blank.'],
    'nonpositive width' => ['logo.svg', 0, 'The logo width must be greater than zero.'],
]);

it('replaces only the existing managed region and keeps its anchor when sections are removed', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $before = "# Welcome\r\n\r\n";
        $start = "<!-- MAINTAINER:README_HEAD:START -->\r\n";
        $end = '<!-- MAINTAINER:README_HEAD:END -->';
        $after = "\r\n\r\n```markdown\r\n```html\r\n".$start.$end."\r\n```\r\n\r\nKeep this text.";
        $files->put($readme, $before.$start."<div>Old header</div>\r\n".$end.$after);
        $writer = new ReadmeHeadWriter($files);

        expect($writer->update($directory, new Head, '1.0.0'))->toBeTrue()
            ->and($files->get($readme))->toBe($before.$start.$end.$after);

        $head = (new Head)->withDescription('New description');

        expect($writer->update($directory, $head, '1.0.0'))->toBeTrue()
            ->and($files->get($readme))->toBe($before.$start
                ."<p id=\"maintainer-description\" align=\"center\">\r\n    New description\r\n</p>\r\n"
                .$end.$after);
    });
});

it('leaves an unmarked README alone when the head is empty', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $files->put($readme, "# Example\n");

        expect(new ReadmeHeadWriter($files)->update($directory, new Head, '1.0.0'))->toBeFalse()
            ->and($files->get($readme))->toBe("# Example\n");
    });
});

it('leaves the README alone if a version badge has no version to display', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $contents = "# Example\n";
        $files->put($readme, $contents);
        $head = (new Head)->addBadge(Badge::releaseVersion('https://example.com/releases'));

        expect(fn () => new ReadmeHeadWriter($files)->update($directory, $head, null))
            ->toThrow(LogicException::class, 'Declare public const string VERSION')
            ->and($files->get($readme))->toBe($contents);
    });
});

it('rejects ambiguous README head markers before writing', function (array $markers) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($markers): void {
        $readme = $directory.'/README.md';
        $contents = implode("\n", array_map(
            static fn (string $marker): string => "<!-- MAINTAINER:README_HEAD:{$marker} -->",
            $markers,
        ))."\n\nKeep this text.";
        $files->put($readme, $contents);

        expect(fn () => new ReadmeHeadWriter($files)->update($directory, (new Head)->withTitle('New'), '1.0.0'))
            ->toThrow(RuntimeException::class, 'one matching pair')
            ->and($files->get($readme))->toBe($contents);
    });
})->with([
    'unpaired' => [['START']],
    'reversed' => [['END', 'START']],
    'duplicate' => [['START', 'END', 'START', 'END']],
]);
