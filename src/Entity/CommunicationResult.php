<?php

declare(strict_types=1);

namespace App\Entity;

enum CommunicationResult: string
{
    case Notified    = 'notified';
    case NotNotified = 'not_notified';
}
