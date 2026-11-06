<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;

/**
 * Una fila del informe de salidas: las cifras de un grupo (o, con $group a null, el total de un curso) y cuántas salidas
 * hay de cada motivo.
 */
final readonly class ExitGroupRow
{
    public function __construct(
        public ?Group $group,
        public ExitTotals $totals,
        /** @var array<string, int> id (RFC 4122) del motivo => salidas */
        public array $byReason,
    ) {}
}
