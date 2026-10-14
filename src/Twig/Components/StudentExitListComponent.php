<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\StudentExitRepository;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Salidas autorizadas de un estudiante, de la más reciente a la más antigua: solo las de hoy
 * (al autorizar una salida) o todo su historial (en la ficha del alumno).
 */
#[AsLiveComponent]
class StudentExitListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public Student $student;

    #[LiveProp]
    public bool $todayOnly = true;

    public function __construct(
        private readonly StudentExitRepository $exits,
        private readonly AppSettings $appSettings,
        private readonly ClockInterface $clock,
    ) {}

    public function mount(Student $student, bool $todayOnly = true): void
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $this->student   = $student;
        $this->todayOnly = $todayOnly;
    }

    /** @return Paginator<StudentExit> */
    public function getPagination(): Paginator
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->todayOnly) {
            return $this->paginate($this->exits->createStudentHistoryQuery($this->student));
        }

        $from = $this->clock->now()->setTime(0, 0, 0);

        return $this->paginate($this->exits->createStudentDayQuery($this->student, $from, $from->modify('+1 day')));
    }
}
