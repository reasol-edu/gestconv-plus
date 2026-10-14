<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Repository\ExitReasonRepository;
use App\Service\Catalog\AbstractCatalogExporter;
use Symfony\Component\Clock\ClockInterface;

class ExitReasonExporter extends AbstractCatalogExporter
{
    public function __construct(
        private readonly ExitReasonRepository $reasons,
        ClockInterface $clock,
    ) {
        parent::__construct($clock);
    }

    protected function itemsKey(): string
    {
        return 'reasons';
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->reasons->findByCentreOrdered($centre);
    }
}
