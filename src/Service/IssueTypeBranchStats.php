<?php

declare(strict_types=1);

namespace App\Service;

/** Los tipos de incidencia que cuelgan de un mismo tipo raíz en el informe, con su subtotal. */
final readonly class IssueTypeBranchStats
{
    public function __construct(
        public string $rootName,
        /** @var list<IssueStatsRow> */
        public array $rows,
        public IssueStatsRow $total,
    ) {}
}
