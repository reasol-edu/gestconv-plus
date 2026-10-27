<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ResourceBooking;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Entity\Group;
use App\Repository\GroupRepository;
use App\Repository\ResourceBookingRepository;
use App\Repository\ResourceCategoryRepository;
use App\Repository\SharedResourceRepository;
use App\Repository\TeacherRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Security\Voter\ResourceBookingVoter;
use App\Service\BookingDateCheck;
use App\Service\BookingInput;
use App\Service\ResourceBookingAccess;
use App\Service\ResourceBookingCalendarBuilder;
use App\Service\ResourceBookingFilters;
use App\Service\ResourceBookingInputReader;
use App\Service\ResourceBookingManager;
use App\Service\ResourceBookingPlanner;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reservas de recursos compartidos: calendario semanal y mensual, listado, alta (con periodicidad semanal y su
 * previsualización), edición, baja y validación.
 */
#[Route('/reservas')]
class ResourceBookingController extends AbstractController
{
    use PastYearGuardTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly ResourceBookingRepository $bookings,
        private readonly SharedResourceRepository $resources,
        private readonly ResourceCategoryRepository $categories,
        private readonly TimeSlotRepository $timeSlots,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
        private readonly ResourceBookingAccess $access,
        private readonly ResourceBookingPlanner $planner,
        private readonly ResourceBookingInputReader $reader,
        private readonly ResourceBookingManager $manager,
        private readonly ResourceBookingCalendarBuilder $calendar,
    ) {}

    // ── Calendario ────────────────────────────────────────────────────────────

    #[Route('', name: 'app_bookings_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $year = $this->requireYear($centre);

        $view    = $request->query->getString('view') === 'month' ? 'month' : 'week';
        $anchor  = $this->parseDate($request->query->getString('date')) ?? $this->planner->today();
        $filters = $this->filtersFromQuery($request);

        $data = $view === 'month'
            ? $this->calendar->month($year, $anchor, $filters)
            : $this->calendar->week($year, $anchor, $filters);

        $step = $view === 'month'
            ? [
                'prev' => $anchor->modify('first day of this month')->modify('-1 month'),
                'next' => $anchor->modify('first day of this month')->modify('+1 month'),
            ]
            : ['prev' => $anchor->modify('-7 days'), 'next' => $anchor->modify('+7 days')];

        return $this->render('resource_booking/index.html.twig', [
            'centre'       => $centre,
            'view'         => $view,
            'anchor'       => $anchor,
            'prevDate'     => $step['prev'],
            'nextDate'     => $step['next'],
            'today'        => $this->planner->today(),
            'data'         => $data,
            'filters'      => $filters,
            'filterOptions' => $this->filterOptions($centre, $year),
            'canBook'      => $this->canBookAnything($centre),
            'hasFilters'   => !$filters->isEmpty(),
        ]);
    }

    // ── Listado ───────────────────────────────────────────────────────────────

    #[Route('/listado', name: 'app_bookings_list', methods: ['GET'])]
    public function list(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $year = $this->requireYear($centre);

        // Quien entra en su listado ve ya cómo se resolvieron sus solicitudes: la campana deja de avisarlas.
        $user = $this->getUser();
        if ($user instanceof Teacher) {
            $this->bookings->markResolutionsSeen($user, $year);
        }

        return $this->render('resource_booking/list.html.twig', [
            'centre'  => $centre,
            'canBook' => $this->canBookAnything($centre),
        ]);
    }

    // ── Alta ──────────────────────────────────────────────────────────────────

    #[Route('/nueva', name: 'app_bookings_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $this->denyIfViewingPastYear($centre);
        $year  = $this->requireYear($centre);
        $actor = $this->requireTeacher();

        $errors = [];
        $values = $this->defaultValues($request, $actor);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_resource_booking', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = $this->valuesFromRequest($request);
            $input  = $this->reader->read($request, $centre, $year, $actor);
            $errors = $input->errors;

            if ($input->isValid() && $input->resource !== null && $input->date !== null) {
                $dates = $input->repeat && $input->until !== null
                    ? $this->planner->weeklyDates($input->date, $input->until)
                    : [$input->date];
                $checks = $this->planner->check($year, $input->resource, $actor, $input->slots, $dates);

                if ($input->repeat && !$request->request->getBoolean('confirmed')) {
                    // Una periodicidad se previsualiza: cada fecha con su problema, para elegir cuáles crear.
                    return $this->render('resource_booking/preview.html.twig', [
                        'centre'  => $centre,
                        'input'   => $input,
                        'checks'  => $checks,
                        'carry'   => $this->carryFields($request),
                        'status'  => $this->planner->initialStatus($input->resource, $actor),
                        'capped'  => count($dates) >= ResourceBookingPlanner::MAX_OCCURRENCES,
                    ]);
                }

                $selected = $input->repeat ? $this->selectedDates($request) : null;
                $free     = [];
                foreach ($checks as $check) {
                    if ($check->isFree() && ($selected === null || isset($selected[$check->date->format('Y-m-d')]))) {
                        $free[] = $check->date;
                    }
                }

                if ($free === []) {
                    $errors['date'] = $input->repeat ? 'booking.error.none_selected' : $this->problemKey($checks[0] ?? null);
                } else {
                    $created = $this->manager->create($year, $input, $actor, $free);
                    $this->addFlash('success', $this->t($created[0]->isPending() ? 'booking.flash.created_pending' : 'booking.flash.created'));

                    return $this->redirectToRoute('app_bookings_index', ['date' => $created[0]->getDate()->format('Y-m-d')]);
                }
            }
        }

        return $this->renderBookingForm('resource_booking/new.html.twig', $centre, $year, $actor, $values, $errors, null);
    }

    // ── Detalle ───────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'app_bookings_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $booking = $this->requireBooking($id, $centre);

        return $this->render('resource_booking/show.html.twig', [
            'centre'      => $centre,
            'booking'     => $booking,
            'hasSiblings' => $this->manager->hasSiblings($booking),
        ]);
    }

    // ── Edición ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editar', name: 'app_bookings_edit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $this->denyIfViewingPastYear($centre);
        $year    = $this->requireYear($centre);
        $actor   = $this->requireTeacher();
        $booking = $this->requireBooking($id, $centre);
        $this->denyAccessUnlessGranted(ResourceBookingVoter::EDIT, $booking);

        $hasSiblings = $this->manager->hasSiblings($booking);
        $errors      = [];
        $values      = $this->valuesFromBooking($booking);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_resource_booking_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = $this->valuesFromRequest($request);
            $input  = $this->reader->read($request, $centre, $year, $actor, $booking, false);
            $errors = $input->errors;
            $series = $hasSiblings && $request->request->getString('scope') === 'series';

            if ($input->isValid() && $input->resource !== null && $input->date !== null) {
                $errors = $series
                    ? $this->editSeries($booking, $input, $actor, $year)
                    : $this->editOne($booking, $input, $actor, $year);

                if ($errors === []) {
                    $this->addFlash('success', $this->t($booking->isPending() ? 'booking.flash.updated_pending' : 'booking.flash.updated'));

                    return $this->redirectToRoute('app_bookings_show', ['id' => $id]);
                }
            }
        }

        return $this->renderBookingForm('resource_booking/edit.html.twig', $centre, $year, $actor, $values, $errors, $booking, $hasSiblings);
    }

    // ── Baja ──────────────────────────────────────────────────────────────────

    #[Route('/{id}/eliminar', name: 'app_bookings_delete', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $this->denyIfViewingPastYear($centre);
        $booking = $this->requireBooking($id, $centre);
        $this->denyAccessUnlessGranted(ResourceBookingVoter::EDIT, $booking);

        if (!$this->isCsrfTokenValid('delete_resource_booking_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $targets = $request->request->getString('scope') === 'series' ? $this->manager->seriesFrom($booking) : [$booking];
        $this->manager->delete($targets);
        $this->addFlash('success', $this->t(count($targets) > 1 ? 'booking.flash.deleted_series' : 'booking.flash.deleted'));

        return $this->redirectToRoute($request->request->getString('back') === 'list' ? 'app_bookings_list' : 'app_bookings_index');
    }

    // ── Validación ────────────────────────────────────────────────────────────

    #[Route('/{id}/validar', name: 'app_bookings_approve', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function approve(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$booking, $targets] = $this->resolutionTargets($id, $request, $centre, 'resolve_resource_booking_');

        $this->manager->approve($targets, $this->requireTeacher());
        $this->addFlash('success', $this->t(count($targets) > 1 ? 'booking.flash.approved_series' : 'booking.flash.approved'));

        return $this->redirectAfterResolution($request, $booking);
    }

    #[Route('/{id}/rechazar', name: 'app_bookings_reject', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function reject(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$booking, $targets] = $this->resolutionTargets($id, $request, $centre, 'resolve_resource_booking_');

        $this->manager->reject($targets, $this->requireTeacher(), $request->request->getString('reason'));
        $this->addFlash('success', $this->t(count($targets) > 1 ? 'booking.flash.rejected_series' : 'booking.flash.rejected'));

        return $this->redirectAfterResolution($request, $booking);
    }

    /**
     * @return array{0: ResourceBooking, 1: list<ResourceBooking>}
     */
    private function resolutionTargets(string $id, Request $request, EducationalCentre $centre, string $tokenPrefix): array
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $this->denyIfViewingPastYear($centre);
        $booking = $this->requireBooking($id, $centre);
        $this->denyAccessUnlessGranted(ResourceBookingVoter::MANAGE, $booking);

        if (!$this->isCsrfTokenValid($tokenPrefix . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $targets = $request->request->getString('scope') === 'series' ? $this->manager->seriesFrom($booking) : [$booking];

        return [$booking, $targets];
    }

    private function redirectAfterResolution(Request $request, ResourceBooking $booking): Response
    {
        return $request->request->getString('back') === 'list'
            ? $this->redirectToRoute('app_bookings_list')
            : $this->redirectToRoute('app_bookings_show', ['id' => $booking->getId()->toRfc4122()]);
    }

    // ── Edición: una reserva o toda la serie ──────────────────────────────────

    /**
     * @return array<string, string> errores por campo (vacío si se guardó)
     */
    private function editOne(ResourceBooking $booking, BookingInput $input, Teacher $actor, AcademicYear $year): array
    {
        $resource = $input->resource;
        $date     = $input->date;
        if ($resource === null || $date === null) {
            return [];
        }

        $sameSlots = $this->ids($booking->getTimeSlots()->toArray()) === $this->ids($input->slots);
        $moved     = !$resource->getId()->equals($booking->getResource()->getId())
            || $date->format('Y-m-d') !== $booking->getDate()->format('Y-m-d')
            || !$sameSlots;

        // Cambiar de recurso, fecha o tramos exige que haya sitio; corregir solo la descripción o los grupos de una reserva pasada, no.
        if ($moved) {
            $check = $this->planner->check($year, $resource, $actor, $input->slots, [$date], $booking)[0];
            if (!$check->isFree()) {
                return ['date' => $this->problemKey($check)];
            }
        }

        $this->manager->update($booking, $input, $actor);

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function editSeries(ResourceBooking $booking, BookingInput $input, Teacher $actor, AcademicYear $year): array
    {
        $resource = $input->resource;
        if ($resource === null) {
            return [];
        }

        $targets = $this->manager->seriesFrom($booking);

        // Toda la serie cambia de recurso, tramos, docente, grupos y descripción: han de tener sitio todas las fechas.
        $conflicts = [];
        foreach ($targets as $target) {
            $check = $this->planner->check($year, $resource, $actor, $input->slots, [$target->getDate()], $target)[0];
            if (!$check->isFree() && $check->problem !== BookingDateCheck::PAST) {
                $conflicts[] = $target->getDate()->format('d/m/Y');
            }
        }
        if ($conflicts !== []) {
            return ['date' => $this->translator->trans('booking.error.series_conflict', ['%dates%' => implode(', ', $conflicts)], 'admin')];
        }

        foreach ($targets as $target) {
            $this->manager->update($target, $input, $actor, false);
        }

        return [];
    }

    // ── Formulario ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function renderBookingForm(string $template, EducationalCentre $centre, AcademicYear $year, Teacher $actor, array $values, array $errors, ?ResourceBooking $booking, bool $hasSiblings = false): Response
    {
        $slotsByDay = [];
        foreach ($this->timeSlots->findByAcademicYearOrdered($year) as $slot) {
            $slotsByDay[$slot->getDayOfWeek()][] = $slot;
        }
        ksort($slotsByDay);

        $bookable = [];
        foreach ($this->resources->findByCentreOrdered($centre, true) as $resource) {
            $current = $booking !== null && $booking->getResource()->getId()->equals($resource->getId());
            if ($current || $this->isGranted(ResourceBookingVoter::BOOK, $resource)) {
                $bookable[$resource->getCategory()->getName()][] = $resource;
            }
        }
        // Al editar, el recurso actual se ofrece aunque ya no se pueda reservar (inactivo).
        if ($booking !== null) {
            $present = false;
            foreach ($bookable as $list) {
                foreach ($list as $resource) {
                    $present = $present || $resource->getId()->equals($booking->getResource()->getId());
                }
            }
            if (!$present) {
                $bookable[$booking->getResource()->getCategory()->getName()][] = $booking->getResource();
            }
        }

        $canPickTeacher = $this->access->hasManagerRole($actor, $centre);

        return $this->render($template, [
            'centre'          => $centre,
            'values'          => $values,
            'errors'          => $errors,
            'booking'         => $booking,
            'hasSiblings'     => $hasSiblings,
            'resourcesByCategory' => $bookable,
            'slotsByDay'      => $slotsByDay,
            'groups'          => $this->groups->findByActiveYearOfCentreOrderedByName($centre, $year),
            'canPickTeacher'  => $canPickTeacher,
            'teachers'        => $canPickTeacher ? $this->teachers->findByAcademicYearOrderedByName($year) : [],
            'today'           => $this->planner->today(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultValues(Request $request, Teacher $actor): array
    {
        $date = $this->parseDate($request->query->getString('date')) ?? $this->planner->today();
        // Un fin de semana no tiene tramos: se ofrece el lunes siguiente.
        if ((int) $date->format('N') >= 6) {
            $date = $date->modify('next monday');
        }

        return [
            'resource'     => $request->query->getString('resource'),
            'teacher'      => $actor->getId()->toRfc4122(),
            'date'         => $date->format('Y-m-d'),
            'slots'        => [],
            'groups'       => [],
            'description'  => '',
            'repeat'       => false,
            'repeat_until' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesFromRequest(Request $request): array
    {
        return [
            'resource'     => $request->request->getString('resource'),
            'teacher'      => $request->request->getString('teacher'),
            'date'         => $request->request->getString('date'),
            'slots'        => $this->stringList($request->request->all('slots')),
            'groups'       => $this->stringList($request->request->all('groups')),
            'description'  => $request->request->getString('description'),
            'repeat'       => $request->request->getBoolean('repeat'),
            'repeat_until' => $request->request->getString('repeat_until'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesFromBooking(ResourceBooking $booking): array
    {
        return [
            'resource'     => $booking->getResource()->getId()->toRfc4122(),
            'teacher'      => $booking->getTeacher()->getId()->toRfc4122(),
            'date'         => $booking->getDate()->format('Y-m-d'),
            'slots'        => $this->ids($booking->getTimeSlots()->toArray()),
            'groups'       => $this->ids($booking->getGroups()->toArray()),
            'description'  => $booking->getDescription() ?? '',
            'repeat'       => false,
            'repeat_until' => '',
        ];
    }

    /**
     * Campos del formulario que la previsualización devuelve tal cual al confirmar.
     *
     * @return array<string, string|list<string>>
     */
    private function carryFields(Request $request): array
    {
        return [
            'resource'     => $request->request->getString('resource'),
            'teacher'      => $request->request->getString('teacher'),
            'date'         => $request->request->getString('date'),
            'slots'        => $this->stringList($request->request->all('slots')),
            'groups'       => $this->stringList($request->request->all('groups')),
            'description'  => $request->request->getString('description'),
            'repeat'       => '1',
            'repeat_until' => $request->request->getString('repeat_until'),
        ];
    }

    /**
     * @return array<string, true> fechas (Y-m-d) marcadas en la previsualización
     */
    private function selectedDates(Request $request): array
    {
        $selected = [];
        foreach ($request->request->all('dates') as $date) {
            if (is_string($date)) {
                $selected[$date] = true;
            }
        }

        return $selected;
    }

    private function problemKey(?BookingDateCheck $check): string
    {
        return match ($check?->problem) {
            BookingDateCheck::OCCUPIED    => 'booking.error.occupied',
            BookingDateCheck::NON_WORKING => 'booking.error.non_working',
            BookingDateCheck::PAST        => 'booking.error.past',
            BookingDateCheck::TOO_FAR     => 'booking.error.too_far',
            BookingDateCheck::NO_SLOT     => 'booking.error.no_slot',
            default                       => 'booking.error.date',
        };
    }

    // ── Filtros del calendario ────────────────────────────────────────────────

    private function filtersFromQuery(Request $request): ResourceBookingFilters
    {
        return new ResourceBookingFilters(
            categoryId: $request->query->getString('category'),
            resourceId: $request->query->getString('resource'),
            teacherId: $request->query->getString('teacher'),
            groupId: $request->query->getString('group'),
        );
    }

    /**
     * @return array{categories: list<\App\Entity\ResourceCategory>, resources: list<SharedResource>, teachers: list<Teacher>, groups: list<\App\Entity\Group>}
     */
    private function filterOptions(EducationalCentre $centre, AcademicYear $year): array
    {
        return [
            'categories' => $this->categories->findByCentreOrdered($centre),
            'resources'  => $this->resources->findByCentreOrdered($centre),
            'teachers'   => array_values($this->teachers->findByAcademicYearOrderedByName($year)),
            'groups'     => array_values($this->groups->findByActiveYearOfCentreOrderedByName($centre, $year)),
        ];
    }

    /** Hay algún recurso que el usuario pueda reservar (para ofrecer el botón «Reservar»). */
    private function canBookAnything(EducationalCentre $centre): bool
    {
        foreach ($this->resources->findByCentreOrdered($centre, true) as $resource) {
            if ($this->isGranted(ResourceBookingVoter::BOOK, $resource)) {
                return true;
            }
        }

        return false;
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function requireYear(EducationalCentre $centre): AcademicYear
    {
        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        return $year;
    }

    private function requireTeacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** La reserva, del curso visualizado y del centro seleccionado; si no, 404. */
    private function requireBooking(string $id, EducationalCentre $centre): ResourceBooking
    {
        $booking = $this->bookings->findById($id);
        if (
            $booking === null
            || !$booking->getResource()->getCategory()->getEducationalCentre()->getId()->equals($centre->getId())
            || $booking->getAcademicYear()->getId()->toRfc4122() !== $this->requireYear($centre)->getId()->toRfc4122()
        ) {
            throw $this->createNotFoundException();
        }

        return $booking;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * @param array<array-key, TimeSlot|Group> $entities
     * @return list<string>
     */
    private function ids(array $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = $entity->getId()->toRfc4122();
        }
        sort($ids);

        return $ids;
    }
}
