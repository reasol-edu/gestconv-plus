<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\SchoolEvent;
use App\Entity\SchoolEventType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\SchoolEventRepository;
use App\Repository\SchoolEventTypeRepository;
use App\Repository\StudentRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\AppSettingsInterface;
use App\Service\PdfHeaderBuilder;
use App\Service\PdfRenderer;
use App\Service\SchoolEventRosterBuilder;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/actividades')]
class SchoolEventController extends AbstractController
{
    use PastYearGuardTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly SchoolEventRepository $events,
        private readonly GroupRepository $groups,
        private readonly SchoolEventTypeRepository $types,
        private readonly TeacherRepository $teachers,
        private readonly StudentRepository $students,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly AppSettingsInterface $settings,
        private readonly PdfRenderer $pdfRenderer,
        private readonly PdfHeaderBuilder $pdfHeaderBuilder,
        private readonly SchoolEventRosterBuilder $rosterBuilder,
    ) {}

    #[Route('', name: 'app_events_index', methods: ['GET'])]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);

        return $this->render('school_event/index.html.twig', ['centre' => $centre]);
    }

    #[Route('/nuevo', name: 'app_events_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);
        $this->denyIfViewingPastYear($centre);

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $errors = [];
        $values = $this->emptyValues();
        $values['date'] = trim($request->query->getString('date'));
        // Un tipo es obligatorio: de entrada va elegido el primero del catálogo.
        $values['type'] = ($this->types->findActiveByCentreOrdered($centre)[0] ?? null)?->getId()->toRfc4122() ?? '';
        $selectedGroupIds = [];
        $selectedCompanionIds = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_school_event', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values               = $this->valuesFromRequest($request);
            $selectedGroupIds     = $request->request->all('groups');
            $selectedCompanionIds = $request->request->all('companions');
            $errors               = $this->validate($values, $selectedGroupIds, $centre, null);

            if (empty($errors)) {
                $author = $this->getUser();
                $event  = (new SchoolEvent())
                    ->setAcademicYear($year)
                    ->setCreatedBy($author instanceof Teacher ? $author : null);
                $this->applyValues($event, $values, $centre, $year, $selectedGroupIds, $selectedCompanionIds);

                $this->em->persist($event);
                $this->em->flush();

                $this->activityLog->log('school_event.created', [
                    'entityId' => $event->getId()->toRfc4122(),
                    'name'     => $event->getName(),
                ]);

                $this->addFlash('success', $this->t('school_event.flash.created'));

                return $this->redirectToRoute('app_events_index');
            }
        }

        return $this->render('school_event/new.html.twig', [
            'centre'               => $centre,
            'errors'               => $errors,
            'values'               => $values,
            'availableGroups'      => $this->groups->findByActiveYearOfCentreOrderedByName($centre),
            'selectedGroupIds'     => $selectedGroupIds,
            'availableTypes'       => $this->types->findActiveByCentreOrdered($centre),
            'availableTeachers'    => $this->teachers->findByAcademicYearOrderedByName($year),
            'selectedCompanionIds' => $selectedCompanionIds,
        ]);
    }

    #[Route('/{eventId}/editar', name: 'app_events_edit', methods: ['GET', 'POST'])]
    public function edit(string $eventId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);
        $this->denyIfViewingPastYear($centre);

        $event = $this->findManageable($centre, $eventId);
        $year  = $event->getAcademicYear();

        $errors = [];
        $values = [
            'date'        => $event->getDate()->format('Y-m-d'),
            'start_time'  => $event->getStartTime()->format('H:i'),
            'end_time'    => $event->getEndTime()->format('H:i'),
            'name'        => $event->getName(),
            'description' => $event->getDescription() ?? '',
            'url'         => $event->getUrl() ?? '',
            'scope'       => $event->isGeneral() ? 'general' : 'restricted',
            'type'        => $event->getType()?->getId()->toRfc4122() ?? '',
            'organizing_department' => $event->getOrganizingDepartment() ?? '',
            'location'    => $event->getLocation() ?? '',
            'coordinator' => $event->getCoordinator()?->getId()->toRfc4122() ?? '',
            'draft'       => $event->isDraft() ? '1' : '0',
        ];
        $selectedGroupIds = array_map(
            static fn (Group $g): string => $g->getId()->toRfc4122(),
            $event->getGroups()->toArray(),
        );
        $selectedCompanionIds = array_map(
            static fn (Teacher $t): string => $t->getId()->toRfc4122(),
            $event->getCompanions()->toArray(),
        );

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_school_event_' . $eventId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values               = $this->valuesFromRequest($request);
            $selectedGroupIds     = $request->request->all('groups');
            $selectedCompanionIds = $request->request->all('companions');
            $errors               = $this->validate($values, $selectedGroupIds, $centre, $event);

            if (empty($errors)) {
                $this->applyValues($event, $values, $centre, $year, $selectedGroupIds, $selectedCompanionIds);
                $this->em->flush();

                $this->activityLog->log('school_event.updated', [
                    'entityId' => $event->getId()->toRfc4122(),
                    'name'     => $event->getName(),
                ]);

                $this->addFlash('success', $this->t('school_event.flash.saved'));

                return $this->redirectToRoute('app_events_index');
            }
        }

        return $this->render('school_event/edit.html.twig', [
            'centre'               => $centre,
            'event'                => $event,
            'errors'               => $errors,
            'values'               => $values,
            'availableGroups'      => $this->groups->findByActiveYearOfCentreOrderedByName($centre),
            'selectedGroupIds'     => $selectedGroupIds,
            'availableTypes'       => $this->selectableTypes($centre, $event),
            'availableTeachers'    => $this->selectableTeachers($year, $event),
            'selectedCompanionIds' => $selectedCompanionIds,
        ]);
    }

    #[Route('/{eventId}', name: 'app_events_show', requirements: ['eventId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $eventId, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);

        $event = $this->findManageable($centre, $eventId);

        return $this->render('school_event/show.html.twig', [
            'centre'  => $centre,
            'event'   => $event,
            'rosters' => $this->rosterBuilder->build($event),
        ]);
    }

    /**
     * Pantalla para elegir, entre el alumnado de los grupos de la actividad, quiénes participan: agrupado por curso y
     * grupo, con búsqueda y «seleccionar todos» global y por grupo.
     */
    #[Route('/{eventId}/alumnado', name: 'app_events_students', requirements: ['eventId' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function students(string $eventId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);
        $this->denyIfViewingPastYear($centre);

        $event  = $this->findManageable($centre, $eventId);
        $groups = array_values($event->getGroups()->toArray());
        usort($groups, static fn (Group $a, Group $b): int => strnatcasecmp($a->getCourse()->getName(), $b->getCourse()->getName())
            ?: strnatcasecmp($a->getName(), $b->getName()));

        $studentsByGroup = [];
        $allowed         = [];
        foreach ($this->students->findByGroupsOrdered($groups) as $student) {
            $allowed[$student->getId()->toRfc4122()] = $student;
            foreach ($student->getGroups() as $group) {
                $studentsByGroup[$group->getId()->toRfc4122()][] = $student;
            }
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('event_students_' . $eventId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            // Solo cuenta quien pertenece a alguno de los grupos de la actividad.
            $chosen = [];
            foreach ($request->request->all('students') as $studentId) {
                if (is_string($studentId) && isset($allowed[$studentId])) {
                    $chosen[$studentId] = $allowed[$studentId];
                }
            }
            foreach ($event->getStudents()->toArray() as $student) {
                if (!isset($chosen[$student->getId()->toRfc4122()])) {
                    $event->removeStudent($student);
                }
            }
            foreach ($chosen as $student) {
                $event->addStudent($student);
            }
            $this->em->flush();

            $this->activityLog->log('school_event.updated', [
                'entityId' => $event->getId()->toRfc4122(),
                'name'     => $event->getName(),
            ]);
            $this->addFlash('success', $this->t('school_event.flash.students_saved'));

            return $this->redirectToRoute('app_events_show', ['eventId' => $eventId]);
        }

        $selectedIds = array_map(static fn (Student $s): string => $s->getId()->toRfc4122(), $event->getStudents()->toArray());
        $courses     = [];
        foreach ($groups as $group) {
            $courseId = $group->getCourse()->getId()->toRfc4122();
            $courses[$courseId]['course']   = $group->getCourse();
            $courses[$courseId]['groups'][] = ['group' => $group, 'students' => $studentsByGroup[$group->getId()->toRfc4122()] ?? []];
        }

        return $this->render('school_event/students.html.twig', [
            'centre'      => $centre,
            'event'       => $event,
            'courses'     => array_values($courses),
            'selectedIds' => $selectedIds,
        ]);
    }

    /**
     * Informe PDF de la actividad. Con `options=1` solo desglosa el alumnado de los grupos marcados en `groups[]`
     * (el número de estudiantes de cada grupo sale siempre); sin ese parámetro, lista a todos.
     */
    #[Route('/{eventId}/informe', name: 'app_events_report', requirements: ['eventId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function report(string $eventId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);

        $event   = $this->findManageable($centre, $eventId);
        $rosters = $this->rosterBuilder->build($event);

        $listed = [];
        foreach ($rosters as $roster) {
            $listed[$roster->group->getId()->toRfc4122()] = true;
        }
        if ($request->query->getBoolean('options')) {
            $chosen = array_flip(array_filter($request->query->all('groups'), 'is_string'));
            $listed = array_intersect_key($listed, $chosen);
        }

        $title        = $this->t('pdf.school_event.title');
        $placeholders = [
            'title'         => $title,
            'event_name'    => $event->getName(),
            'event_date'    => $event->getDate()->format('d/m/Y'),
            'centre_name'   => $centre->getName(),
            'academic_year' => $event->getAcademicYear()->getName(),
        ];

        return $this->pdfRenderer->render(
            'pdf/school_event.html.twig',
            [
                'centre'     => $centre,
                'event'      => $event,
                'rosters'    => $rosters,
                'listed'     => $listed,
                'footerHtml' => $this->pdfHeaderBuilder->buildFooter('school_event', $centre, $placeholders),
            ],
            $title . ' — ' . $event->getName(),
            sprintf('actividad-%s.pdf', substr($event->getId()->toRfc4122(), 0, 8)),
            header: $this->pdfHeaderBuilder->build('school_event', $centre, $placeholders),
            draftWatermark: $event->isDraft() && $this->settings->getForCentre('reports.draft_watermark_enabled', $centre) === true,
            centre: $centre,
            reportType: 'school_event',
        );
    }

    #[Route('/{eventId}/eliminar', name: 'app_events_delete', methods: ['POST'])]
    public function delete(string $eventId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);
        $this->denyIfViewingPastYear($centre);

        $event = $this->findManageable($centre, $eventId);

        if (!$this->isCsrfTokenValid('delete_school_event_' . $eventId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($event);
        $this->em->flush();

        $this->activityLog->log('school_event.deleted', [
            'entityId' => $eventId,
        ]);

        $this->addFlash('success', $this->t('school_event.flash.deleted'));

        return $this->redirectToRoute('app_events_index');
    }

    /**
     * La actividad del curso visualizado que quien publica puede gestionar: la dirección, los
     * administradores y la coordinación de actividades, cualquiera; el resto del profesorado (con el
     * ajuste «Todos»), solo las que ha publicado él.
     */
    private function findManageable(EducationalCentre $centre, string $eventId): SchoolEvent
    {
        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$this->isGranted(EducationalCentreVoter::MANAGE_ALL_EVENTS, $centre) && $user instanceof Teacher) {
            $event = $this->events->findOwnedByAcademicYearAndId($user, $year, $eventId);
        } else {
            $event = $this->events->findByAcademicYearAndId($year, $eventId);
        }

        return $event ?? throw $this->createNotFoundException();
    }

    /** @return array<string, string> */
    private function emptyValues(): array
    {
        return [
            'date'        => '',
            'start_time'  => '',
            'end_time'    => '',
            'name'        => '',
            'description' => '',
            'url'         => '',
            'scope'       => '',
            'type'        => '',
            'organizing_department' => '',
            'location'    => '',
            'coordinator' => '',
            'draft'       => '0',
        ];
    }

    /** @return array<string, string> */
    private function valuesFromRequest(Request $request): array
    {
        return [
            'date'        => trim($request->request->getString('date')),
            'start_time'  => trim($request->request->getString('start_time')),
            'end_time'    => trim($request->request->getString('end_time')),
            'name'        => trim($request->request->getString('name')),
            'description' => trim($request->request->getString('description')),
            'url'         => trim($request->request->getString('url')),
            'scope'       => trim($request->request->getString('scope')),
            'type'        => trim($request->request->getString('type')),
            'organizing_department' => trim($request->request->getString('organizing_department')),
            'location'    => trim($request->request->getString('location')),
            'coordinator' => trim($request->request->getString('coordinator')),
            'draft'       => $request->request->getString('draft') === '1' ? '1' : '0',
        ];
    }

    /**
     * Docentes que se pueden elegir como coordinación o acompañantes: los del curso y, al editar, quienes ya
     * figuran en la actividad aunque hayan dejado de estar en el curso (para no perderlos al guardar).
     *
     * @return list<Teacher>
     */
    private function selectableTeachers(AcademicYear $year, ?SchoolEvent $event): array
    {
        $teachers = array_values($this->teachers->findByAcademicYearOrderedByName($year));
        if ($event === null) {
            return $teachers;
        }

        $known = [];
        foreach ($teachers as $teacher) {
            $known[$teacher->getId()->toRfc4122()] = true;
        }
        foreach ([$event->getCoordinator(), ...$event->getCompanions()->toArray()] as $current) {
            if ($current !== null && !isset($known[$current->getId()->toRfc4122()])) {
                $teachers[] = $current;
                $known[$current->getId()->toRfc4122()] = true;
            }
        }

        return $teachers;
    }

    /**
     * Tipos que se pueden elegir: los activos del catálogo y, al editar, el que ya tiene la actividad
     * aunque se haya desactivado después.
     *
     * @return list<SchoolEventType>
     */
    private function selectableTypes(EducationalCentre $centre, ?SchoolEvent $event): array
    {
        $types   = $this->types->findActiveByCentreOrdered($centre);
        $current = $event?->getType();
        if ($current !== null && !$current->isActive()) {
            $types[] = $current;
        }

        return $types;
    }

    /**
     * @param array<string, string> $values
     * @param array<array-key, mixed> $selectedGroupIds
     *
     * @return array<string, string>
     */
    private function validate(array $values, array $selectedGroupIds, EducationalCentre $centre, ?SchoolEvent $event): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = $this->t('school_event.error.name_required');
        }

        $type = $this->types->findById($values['type']);
        if ($type === null || $type->getEducationalCentre() !== $centre || (!$type->isActive() && $type !== $event?->getType())) {
            $errors['type'] = $this->t('school_event.error.type_required');
        }

        if ($this->parseDate($values['date']) === null) {
            $errors['date'] = $this->t('school_event.error.date_required');
        }

        $start = $this->parseTime($values['start_time']);
        $end   = $this->parseTime($values['end_time']);
        if ($start === null || $end === null) {
            $errors['time'] = $this->t('school_event.error.time_required');
        } elseif ($start >= $end) {
            $errors['time'] = $this->t('school_event.error.time_invalid');
        }

        // Sin grupos el evento es general sin más; solo con grupos hay que elegir entre las dos opciones.
        if ($selectedGroupIds !== [] && !in_array($values['scope'], ['general', 'restricted'], true)) {
            $errors['scope'] = $this->t('school_event.error.scope_required');
        }

        if ($values['url'] !== '' && filter_var($values['url'], FILTER_VALIDATE_URL) === false) {
            $errors['url'] = $this->t('school_event.error.url_invalid');
        }

        return $errors;
    }

    /**
     * @param array<string, string>   $values
     * @param array<array-key, mixed> $selectedGroupIds
     * @param array<array-key, mixed> $selectedCompanionIds
     */
    private function applyValues(
        SchoolEvent $event,
        array $values,
        EducationalCentre $centre,
        AcademicYear $year,
        array $selectedGroupIds,
        array $selectedCompanionIds,
    ): void {
        // Coordinador y acompañantes han de ser docentes del curso de la actividad (o quienes ya lo eran al guardarla); lo demás se ignora.
        $teachersById = [];
        foreach ($this->selectableTeachers($year, $event) as $teacher) {
            $teachersById[$teacher->getId()->toRfc4122()] = $teacher;
        }
        $coordinator = $teachersById[$values['coordinator']] ?? null;
        $companions  = [];
        foreach ($selectedCompanionIds as $teacherId) {
            if (is_string($teacherId) && isset($teachersById[$teacherId]) && $teachersById[$teacherId] !== $coordinator) {
                $companions[$teacherId] = $teachersById[$teacherId];
            }
        }

        $centreGroupsById = [];
        foreach ($this->groups->findByActiveYearOfCentreOrderedByName($centre) as $group) {
            $centreGroupsById[$group->getId()->toRfc4122()] = $group;
        }

        $selected = [];
        foreach ($selectedGroupIds as $groupId) {
            if (is_string($groupId) && isset($centreGroupsById[$groupId])) {
                $selected[$groupId] = $centreGroupsById[$groupId];
            }
        }

        // Un evento puede estar asociado a grupos sea general o no: sin grupos es general sin más; con
        // grupos, «general» lo ve todo el profesorado y «restringido» solo quien se relaciona con ellos.
        $general = $selected === [] || $values['scope'] === 'general';

        $event->setDate($this->parseDate($values['date']) ?? $event->getDate())
            ->setStartTime($this->parseTime($values['start_time']) ?? $event->getStartTime())
            ->setEndTime($this->parseTime($values['end_time']) ?? $event->getEndTime())
            ->setName($values['name'])
            ->setDescription($values['description'] !== '' ? $values['description'] : null)
            ->setUrl($values['url'] !== '' ? $values['url'] : null)
            ->setGeneral($general)
            ->setType($this->types->findById($values['type']))
            ->setOrganizingDepartment($values['organizing_department'] !== '' ? $values['organizing_department'] : null)
            ->setLocation($values['location'] !== '' ? $values['location'] : null)
            ->setCoordinator($coordinator)
            ->setDraft($values['draft'] === '1');

        foreach ($event->getCompanions()->toArray() as $teacher) {
            $event->removeCompanion($teacher);
        }
        foreach ($companions as $teacher) {
            $event->addCompanion($teacher);
        }

        foreach ($event->getGroups()->toArray() as $group) {
            $event->removeGroup($group);
        }

        foreach ($selected as $group) {
            $event->addGroup($group);
        }

        // El alumnado se elige en su propia pantalla; aquí solo se descarta el de los grupos que se han quitado.
        $keep = [];
        foreach ($this->students->findByGroupsOrdered(array_values($selected)) as $student) {
            $keep[$student->getId()->toRfc4122()] = true;
        }
        foreach ($event->getStudents()->toArray() as $student) {
            if (!isset($keep[$student->getId()->toRfc4122()])) {
                $event->removeStudent($student);
            }
        }
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false ? $date : null;
    }

    private function parseTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('H:i', $value);

        return $time !== false ? $time : null;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
