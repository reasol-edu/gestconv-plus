<?php

declare(strict_types=1);

namespace App\Entity;

/** Estado de una incidencia. Abierta y en proceso son los estados «abiertos»; los otros tres la cierran. */
enum IssueStatus: string
{
    case Open          = 'open';
    case InProgress    = 'in_progress';
    case Duplicate     = 'duplicate';
    case NotApplicable = 'not_applicable';
    case Solved        = 'solved';

    /** Aún no se ha cerrado: abierta o en proceso. */
    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    public function isClosed(): bool
    {
        return !$this->isOpen();
    }

    /** @return list<self> */
    public static function openStatuses(): array
    {
        return [self::Open, self::InProgress];
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::openStatuses());
    }

    /** @return list<self> los que cierran la incidencia */
    public static function closedStatuses(): array
    {
        return [self::Duplicate, self::NotApplicable, self::Solved];
    }
}
