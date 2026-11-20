<?php

namespace App\Support;

use ArtisanToolbox\Maintainer\Maintainer;
use Symfony\Component\Console\Color;

final class MaintainerBanner
{
    private const array WORDMARK = [
        ['#FFD27F', '      █▄  ▄█ ▄███▄ ██ ██▄ ██ ██████ ▄███▄ ██ ██▄ ██ █████ █████▄'],
        ['#FFAC68', '╺━╸   ██▄▄██ ██ ██ ██ ███▄██   ██   ██ ██ ██ ███▄██ ██    ██  ██'],
        ['#FF8754', '     ██▀▀██ █████ ██ ██▀███   ██   █████ ██ ██▀███ ████▌ █████▀'],
        ['#FF5947', ' ╺╸  ██  ██ ██ ██ ██ ██ ▀██   ██   ██ ██ ██ ██ ▀██ ██    ██ ▀█▄'],
        ['#EF4771', '    ██  ██ ██ ██ ██ ██  ██   ██   ██ ██ ██ ██  ██ █████ ██  ▀█'],
    ];

    public function render(int $width = 80, bool $decorated = false): string
    {
        $version = 'v'.Maintainer::VERSION;
        $suffix = '  '.($decorated ? new Color(options: ['bold'])->apply($version) : $version);

        if (mb_strwidth(self::WORDMARK[2][1].'  '.$version) > $width) {
            return ($decorated ? new Color('#FF5947')->apply('MAINTAINER') : 'MAINTAINER').$suffix;
        }

        $lines = [];

        foreach (self::WORDMARK as [$color, $line]) {
            $lines[] = $decorated ? new Color($color)->apply($line) : $line;
        }

        $lines[2] .= $suffix;

        return implode(PHP_EOL, $lines);
    }
}
