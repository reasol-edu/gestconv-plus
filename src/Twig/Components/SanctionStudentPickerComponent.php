<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\SanctionRepository;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class SanctionStudentPickerComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    public function __construct(
        private readonly SanctionRepository $sanctions,
        private readonly AppSettings $appSettings,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $this->centre = $centre;
    }

    /** @return Paginator<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, sanctionableCount: int, seriousCount: int, prescribedCount: int}> */
    public function getPagination(): Paginator
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $page    = max(1, $this->page);
        $perPage = $this->appSettings->getInt('page.size');
        $stats   = $this->sanctions->findStudentStatsForCentre($this->centre, $this->search, $page, $perPage);

        return Paginator::fromArray($stats['rows'], $stats['total'], $page, $perPage);
    }
}
