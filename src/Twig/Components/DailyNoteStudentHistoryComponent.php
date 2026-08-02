<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\DailyNote;
use App\Entity\Student;
use App\Pagination\Paginator;
use App\Repository\DailyNoteRepository;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Historial paginado de notas diarias de un estudiante, de más reciente a más antigua, para la
 * ficha del alumno.
 */
#[AsLiveComponent]
class DailyNoteStudentHistoryComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public Student $student;

    #[LiveProp]
    public AcademicYear $academicYear;

    public function __construct(
        private readonly DailyNoteRepository $notes,
        private readonly AppSettings $appSettings,
    ) {}

    public function mount(Student $student, AcademicYear $academicYear): void
    {
        $this->student      = $student;
        $this->academicYear = $academicYear;
    }

    /** @return Paginator<DailyNote> */
    public function getPagination(): Paginator
    {
        return $this->paginate($this->notes->createStudentHistoryQuery($this->student, $this->academicYear));
    }
}
