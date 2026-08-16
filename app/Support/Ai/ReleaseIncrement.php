<?php

namespace App\Support\Ai;

enum ReleaseIncrement: string
{
    case Patch = 'patch';
    case Minor = 'minor';
}
