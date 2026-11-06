<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Lo que el usuario ha pedido en el listado de incidencias, ya saneado.
 */
final readonly class IssueListFilter
{
    public const SCOPE_ALL     = '';
    public const SCOPE_MINE    = 'mine';
    public const SCOPE_MANAGED = 'managed';

    /** Valor de la prioridad para «sin prioridad». */
    public const NO_PRIORITY = 'none';

    public const SORTS = ['number', 'type', 'priority', 'status', 'created', 'due'];

    /**
     * @param list<string> $typeIds    el tipo elegido y todos los que cuelgan de él (vacío: cualquiera)
     * @param list<string> $statuses   valores de estado permitidos
     */
    public function __construct(
        public string $search = '',
        public array $statuses = [],
        public array $typeIds = [],
        public string $priority = '',
        public string $scope = self::SCOPE_ALL,
        public bool $overdueOnly = false,
        public string $sort = 'created',
        public string $sortDir = 'desc',
        public ?\DateTimeImmutable $today = null,
    ) {}
}
