<?php

namespace App\Support;

enum HtmlDiffOutputFormat: string
{
    case LineByLine = 'line_by_line';
    case SideBySide = 'side_by_side';

    public function diff2HtmlValue(): string
    {
        return match ($this) {
            self::LineByLine => 'line-by-line',
            self::SideBySide => 'side-by-side',
        };
    }
}
