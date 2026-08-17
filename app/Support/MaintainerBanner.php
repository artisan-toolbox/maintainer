<?php

namespace App\Support;

use ArtisanToolbox\Maintainer\Maintainer;

final class MaintainerBanner
{
    /**
     * Render the Maintainer terminal banner.
     */
    public function render(): string
    {
        $art = <<<'BANNER'
 __  __       _       _        _
|  \/  | __ _(_)_ __ | |_ __ _(_)_ __   ___ _ __
| |\/| |/ _` | | '_ \| __/ _` | | '_ \ / _ \ '__|
| |  | | (_| | | | | | || (_| | | | | |  __/ |
|_|  |_|\__,_|_|_| |_|\__\__,_|_|_| |_|\___|_|
BANNER;

        return $art.' '.Maintainer::VERSION;
    }
}
