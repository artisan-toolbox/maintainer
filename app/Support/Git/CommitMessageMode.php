<?php

namespace App\Support\Git;

enum CommitMessageMode: string
{
    case Manual = 'manual';
    case Ai = 'ai';
    case AiWithContext = 'ai_with_context';
}
