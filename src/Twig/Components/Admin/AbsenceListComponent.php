<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\AbsenceRepository;
use App\Repository\TeacherRepository;
use App\Service\AppSettings;
use App\Twig\Components\PaginatedListTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class AbsenceListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public AcademicYear $year;

    #[LiveProp(writable: true)]
    public string $dateFrom = '';

    #[LiveProp(writable: true)]
    public string $dateTo = '';

    #[LiveProp(writable: true)]
    public string $teacherId = '';

    public function __construct(
        private readonly AbsenceRepository $absences,
        private readonly TeacherRepository $teachers,
        private readonly AppSettings $appSettings,
    ) {}

    public function mount(AcademicYear $year): void
    {
        $this->year = $year;
    }

    /** @return Paginator<Absence> */
    public function getPagination(): Paginator
    {
        return $this->paginate($this->absences->createFilteredQuery($this->year, [
            'dateFrom'  => $this->dateFrom,
            'dateTo'    => $this->dateTo,
            'teacherId' => $this->teacherId,
        ]));
    }

    public function getSelectedTeacher(): ?Teacher
    {
        if ($this->teacherId === '') {
            return null;
        }

        return $this->teachers->findByAcademicYearAndId($this->year, $this->teacherId);
    }

    public function hasActiveFilters(): bool
    {
        return $this->dateFrom !== '' || $this->dateTo !== '' || $this->teacherId !== '';
    }

    #[LiveAction]
    public function quickToday(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $this->dateFrom = $today;
        $this->dateTo   = $today;
        $this->page     = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->dateFrom  = '';
        $this->dateTo    = '';
        $this->teacherId = '';
        $this->page      = 1;
    }

    public function updatedDateFrom(): void  { $this->page = 1; }
    public function updatedDateTo(): void    { $this->page = 1; }
    public function updatedTeacherId(): void { $this->page = 1; }
}
