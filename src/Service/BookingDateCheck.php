<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ResourceBooking;

/** Resultado de comprobar si un recurso se puede reservar en una fecha: sin `problem` está libre. */
final readonly class BookingDateCheck
{
    public const OCCUPIED    = 'occupied';
    public const NON_WORKING = 'non_working';
    public const PAST        = 'past';
    public const TOO_FAR     = 'too_far';
    public const NO_SLOT     = 'no_slot';

    public function __construct(
        public \DateTimeImmutable $date,
        public ?string $problem = null,
        /** La reserva que ocupa el tramo cuando el problema es OCCUPIED. */
        public ?ResourceBooking $conflict = null,
    ) {}

    public function isFree(): bool
    {
        return $this->problem === null;
    }
}
