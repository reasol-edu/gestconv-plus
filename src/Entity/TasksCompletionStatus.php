<?php

declare(strict_types=1);

namespace App\Entity;

enum TasksCompletionStatus: string
{
    case Unknown = 'unknown';
    case Yes     = 'yes';
    case No      = 'no';
}
