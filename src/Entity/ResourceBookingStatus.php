<?php

declare(strict_types=1);

namespace App\Entity;

/** Estado de una reserva. Pendiente y aprobada ocupan el tramo; rechazada lo libera. */
enum ResourceBookingStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<self> */
    public static function occupying(): array
    {
        return [self::Pending, self::Approved];
    }

    /** @return list<string> */
    public static function occupyingValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::occupying());
    }
}
