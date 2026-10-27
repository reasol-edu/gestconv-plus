<?php

declare(strict_types=1);

namespace App\Service;

/** Filtros del calendario y del listado de reservas: los ids van como texto (vacío = sin filtrar). */
final readonly class ResourceBookingFilters
{
    public function __construct(
        public string $categoryId = '',
        public string $resourceId = '',
        public string $teacherId = '',
        public string $groupId = '',
        public string $status = '',
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->categoryId === '' && $this->resourceId === '' && $this->teacherId === ''
            && $this->groupId === '' && $this->status === '' && $this->dateFrom === null && $this->dateTo === null;
    }
}
