<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Repository\TeacherRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\TenantContext;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Visual editor for the academic year's time slots ("tramos horarios"): five
 * weekday columns (Monday-Friday), each listing its slots ordered by start
 * time, with a detail panel for editing and assigning guard teachers.
 */
#[AsLiveComponent]
class TimeSlotComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    /** Monday(0) .. Friday(4). */
    public const DAYS = [0, 1, 2, 3, 4];

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $selectedId = '';

    /** Inline "add" mini-form, shown under the column whose day matches addDayOfWeek. */
    #[LiveProp(writable: true)]
    public ?int $addDayOfWeek = null;

    /** Standalone "add to all weekdays" form, shown above the board. */
    #[LiveProp(writable: true)]
    public bool $addingAllDays = false;

    #[LiveProp(writable: true)]
    public string $addName = '';

    #[LiveProp(writable: true)]
    public string $addStart = '';

    #[LiveProp(writable: true)]
    public string $addEnd = '';

    /** Detail-panel fields for the selected time slot. */
    #[LiveProp(writable: true)]
    public string $editName = '';

    #[LiveProp(writable: true)]
    public int $editDayOfWeek = 0;

    #[LiveProp(writable: true)]
    public string $editStart = '';

    #[LiveProp(writable: true)]
    public string $editEnd = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public bool $confirmingDelete = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly TimeSlotRepository $timeSlots,
        private readonly TeacherRepository $teachers,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre, string $selectedId = ''): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->centre = $centre;

        if ($selectedId !== '') {
            $this->selectTimeSlot($selectedId);
        }
    }

    public function canWrite(): bool
    {
        return !$this->tenantContext->isViewingNonActiveYear($this->centre);
    }

    // ── Column data ──────────────────────────────────────────────────────────

    /** @return array<int, TimeSlot[]> keyed by day of week (0-4) */
    public function getColumns(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return [];
        }

        $columns = [];
        foreach (self::DAYS as $day) {
            $columns[$day] = $this->timeSlots->findByAcademicYearAndDay($year, $day);
        }

        return $columns;
    }

    /** @return Teacher[] */
    public function getTeacherOptions(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);

        return $year === null ? [] : $this->teachers->findByAcademicYearOrderedByName($year);
    }

    public function getSelectedTimeSlot(): ?TimeSlot
    {
        if ($this->selectedId === '') {
            return null;
        }

        $year = $this->tenantContext->getViewYear($this->centre);

        return $year === null ? null : $this->timeSlots->findByAcademicYearAndId($year, $this->selectedId);
    }

    // ── Selection ────────────────────────────────────────────────────────────

    #[LiveAction]
    public function selectTimeSlot(#[LiveArg] string $id): void
    {
        $this->selectedId       = $id;
        $this->addDayOfWeek     = null;
        $this->errors           = [];
        $this->confirmingDelete = false;

        $timeSlot = $this->getSelectedTimeSlot();
        if ($timeSlot === null) {
            $this->editName = $this->editStart = $this->editEnd = '';
            $this->editDayOfWeek = 0;

            return;
        }

        $this->editName      = $timeSlot->getName();
        $this->editDayOfWeek = $timeSlot->getDayOfWeek();
        $this->editStart     = $timeSlot->getStartTime()->format('H:i');
        $this->editEnd       = $timeSlot->getEndTime()->format('H:i');
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedId = '';
        $this->errors     = [];
    }

    // ── Add ──────────────────────────────────────────────────────────────────

    #[LiveAction]
    public function startAdd(#[LiveArg] int $day): void
    {
        $this->selectedId    = '';
        $this->addDayOfWeek  = $day;
        $this->addingAllDays = false;
        $this->addName       = '';
        $this->addStart      = '';
        $this->addEnd        = '';
        $this->errors        = [];
    }

    #[LiveAction]
    public function cancelAdd(): void
    {
        $this->addDayOfWeek = null;
    }

    #[LiveAction]
    public function startAddAllDays(): void
    {
        $this->selectedId    = '';
        $this->addDayOfWeek  = null;
        $this->addingAllDays = true;
        $this->addName       = '';
        $this->addStart      = '';
        $this->addEnd        = '';
        $this->errors        = [];
    }

    #[LiveAction]
    public function cancelAddAllDays(): void
    {
        $this->addingAllDays = false;
    }

    #[LiveAction]
    public function saveAdd(): void
    {
        $centre = $this->requireWritableCentre();
        $year   = $this->tenantContext->getViewYear($centre);
        $day    = $this->addDayOfWeek;
        if ($year === null || $day === null || !in_array($day, self::DAYS, true)) {
            return;
        }

        $name  = trim($this->addName);
        $start = $this->parseTime($this->addStart);
        $end   = $this->parseTime($this->addEnd);

        if ($name === '' || $start === null || $end === null || $start >= $end) {
            $this->errors = ['add' => $this->t('time_slot.error.invalid')];

            return;
        }

        $timeSlot = (new TimeSlot())
            ->setAcademicYear($year)
            ->setName($name)
            ->setDayOfWeek($day)
            ->setStartTime($start)
            ->setEndTime($end);

        $this->em->persist($timeSlot);
        $this->em->flush();

        $this->addDayOfWeek = null;
        $this->errors = [];
        $this->selectTimeSlot($timeSlot->getId()->toRfc4122());
        $this->flashSuccess($this->t('time_slot.flash.saved'));
    }

    #[LiveAction]
    public function saveAddAllDays(): void
    {
        $centre = $this->requireWritableCentre();
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            return;
        }

        $name  = trim($this->addName);
        $start = $this->parseTime($this->addStart);
        $end   = $this->parseTime($this->addEnd);

        if ($name === '' || $start === null || $end === null || $start >= $end) {
            $this->errors = ['add' => $this->t('time_slot.error.invalid')];

            return;
        }

        foreach (self::DAYS as $day) {
            $timeSlot = (new TimeSlot())
                ->setAcademicYear($year)
                ->setName($name)
                ->setDayOfWeek($day)
                ->setStartTime($start)
                ->setEndTime($end);

            $this->em->persist($timeSlot);
        }
        $this->em->flush();

        $this->addingAllDays = false;
        $this->errors = [];
        $this->flashSuccess($this->t('time_slot.flash.saved'));
    }

    // ── Detail save / delete ─────────────────────────────────────────────────

    #[LiveAction]
    public function saveDetail(): void
    {
        $this->requireWritableCentre();
        $timeSlot = $this->getSelectedTimeSlot();
        if ($timeSlot === null) {
            return;
        }

        $name  = trim($this->editName);
        $start = $this->parseTime($this->editStart);
        $end   = $this->parseTime($this->editEnd);

        if ($name === '' || $start === null || $end === null || $start >= $end
            || !in_array($this->editDayOfWeek, self::DAYS, true)
        ) {
            $this->errors = ['edit' => $this->t('time_slot.error.invalid')];

            return;
        }

        $timeSlot->setName($name)
            ->setDayOfWeek($this->editDayOfWeek)
            ->setStartTime($start)
            ->setEndTime($end);

        $this->em->flush();
        $this->errors = [];
        $this->flashSuccess($this->t('time_slot.flash.saved'));
    }

    #[LiveAction]
    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    #[LiveAction]
    public function deleteSelected(): void
    {
        $this->requireWritableCentre();
        $this->confirmingDelete = false;
        $timeSlot = $this->getSelectedTimeSlot();
        if ($timeSlot === null) {
            return;
        }

        $this->em->remove($timeSlot);
        $this->em->flush();

        $this->selectedId = '';
        $this->flashSuccess($this->t('time_slot.flash.deleted'));
    }

    // ── Guard teachers ───────────────────────────────────────────────────────

    /** @param string[] $ids */
    #[LiveAction]
    public function setGuards(#[LiveArg] array $ids): void
    {
        $this->requireWritableCentre();
        $timeSlot = $this->getSelectedTimeSlot();
        if ($timeSlot === null) {
            return;
        }

        $this->syncTeachers(
            $timeSlot->getGuards(),
            $ids,
            fn (Teacher $t) => $timeSlot->addGuard($t),
            fn (Teacher $t) => $timeSlot->removeGuard($t),
        );
        $this->em->flush();
        $this->flashSuccess($this->t('time_slot.flash.saved'));
    }

    private function resolveTeacher(string $id): ?Teacher
    {
        $year = $this->tenantContext->getViewYear($this->centre);

        return $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $id);
    }

    /**
     * @param Collection<int, Teacher> $current
     * @param string[]                 $ids
     * @param callable(Teacher): mixed $add
     * @param callable(Teacher): mixed $remove
     */
    private function syncTeachers(Collection $current, array $ids, callable $add, callable $remove): void
    {
        $desired = [];
        foreach ($ids as $id) {
            if (Uuid::isValid($id) && ($teacher = $this->resolveTeacher($id)) !== null) {
                $desired[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        foreach ($current as $teacher) {
            if (!isset($desired[$teacher->getId()->toRfc4122()])) {
                $remove($teacher);
            }
        }

        foreach ($desired as $key => $teacher) {
            if (!$current->exists(fn (int $i, Teacher $t) => $t->getId()->toRfc4122() === $key)) {
                $add($teacher);
            }
        }
    }

    private function parseTime(string $value): ?\DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('H:i', $value);

        return $time === false ? null : $time;
    }

    private function requireWritableCentre(): EducationalCentre
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $this->centre);
        if (!$this->canWrite() || $this->centre->getActiveAcademicYear() === null) {
            throw $this->createAccessDeniedException();
        }

        return $this->centre;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    /**
     * LiveAction responses only re-render this component's fragment, not the
     * layout, so a plain addFlash() never reaches the page until the next
     * full navigation. Dispatch a browser event instead so the layout's JS
     * can render the flash immediately.
     */
    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }
}
