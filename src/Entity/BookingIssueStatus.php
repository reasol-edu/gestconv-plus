<?php

declare(strict_types=1);

namespace App\Entity;

/** Estado de la incidencia de una reserva: registrada por el docente, recibida o solucionada por quien gestiona el recurso. */
enum BookingIssueStatus: string
{
    case Reported = 'reported';
    case Received = 'received';
    case Resolved = 'resolved';

    /** Está abierta mientras no se haya solucionado. */
    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }
}
