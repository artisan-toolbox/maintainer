<?php

use App\Support\HtmlDiffOutputFormat;
use App\Support\HtmlDiffRenderer;

it('renders a Git diff with pinned diff2html assets', function () {
    $diff = <<<'DIFF'
        diff --git a/example.txt b/example.txt
        --- a/example.txt
        +++ b/example.txt
        @@ -1 +1 @@
        -before
        +after
        DIFF;

    $html = (new HtmlDiffRenderer)->render($diff, 'Main < working tree', HtmlDiffOutputFormat::LineByLine);

    expect($html)
        ->toContain('<title>Main &lt; working tree</title>')
        ->toContain('diff2html@3.4.56/bundles/css/diff2html.min.css')
        ->toContain('diff2html@3.4.56/bundles/js/diff2html.min.js')
        ->toContain("outputFormat: 'line-by-line'")
        ->toContain(json_encode(base64_encode($diff), JSON_THROW_ON_ERROR));
});

it('renders side-by-side diffs when configured', function () {
    $html = (new HtmlDiffRenderer)->render('', 'Diff', HtmlDiffOutputFormat::SideBySide);

    expect($html)->toContain("outputFormat: 'side-by-side'");
});
