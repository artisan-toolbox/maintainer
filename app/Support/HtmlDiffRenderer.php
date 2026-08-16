<?php

namespace App\Support;

use JsonException;

final class HtmlDiffRenderer
{
    public const string DIFF2HTML_VERSION = '3.4.56';

    /**
     * Render a static HTML document containing a Git diff.
     *
     * @throws JsonException
     */
    public function render(string $diff, string $title, HtmlDiffOutputFormat $outputFormat): string
    {
        $encodedDiff = json_encode(base64_encode($diff), JSON_THROW_ON_ERROR);
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $version = self::DIFF2HTML_VERSION;
        $diff2HtmlOutputFormat = $outputFormat->diff2HtmlValue();

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$escapedTitle}</title>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/diff2html@{$version}/bundles/css/diff2html.min.css">
                <style>
                    body {
                        margin: 0;
                        padding: 2rem;
                        color: #1f2328;
                        background: #f6f8fa;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                    }

                    main {
                        max-width: 1600px;
                        margin: 0 auto;
                    }

                    h1 {
                        margin: 0 0 1.5rem;
                        font-size: 1.5rem;
                    }
                </style>
                <script src="https://cdn.jsdelivr.net/npm/diff2html@{$version}/bundles/js/diff2html.min.js"></script>
            </head>
            <body>
                <main>
                    <h1>{$escapedTitle}</h1>
                    <div id="diff"></div>
                </main>
                <script>
                    const encodedDiff = {$encodedDiff};
                    const binaryDiff = atob(encodedDiff);
                    const diffBytes = Uint8Array.from(binaryDiff, character => character.charCodeAt(0));
                    const diff = new TextDecoder().decode(diffBytes);

                    document.getElementById('diff').innerHTML = Diff2Html.html(diff, {
                        drawFileList: true,
                        matching: 'lines',
                        outputFormat: '{$diff2HtmlOutputFormat}',
                        renderNothingWhenEmpty: false,
                    });
                </script>
            </body>
            </html>
            HTML;
    }
}
