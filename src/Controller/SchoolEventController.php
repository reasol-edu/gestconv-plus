<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\SchoolEventRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/eventos')]
class SchoolEventController extends AbstractController
{
    use PastYearGuardTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly SchoolEventRepository $events,
        private readonly GroupRepository $groups,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
    ) {}

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
        $selectedGroupIds = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_school_event', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values           = $this->valuesFromRequest($request);
            $selectedGroupIds = $request->request->all('groups');
            $errors           = $this->validate($values, $selectedGroupIds);

            if (empty($errors)) {
                $event = (new SchoolEvent())->setAcademicYear($year);
                $this->applyValues($event, $values, $centre, $selectedGroupIds);

                $this->em->persist($event);
                $this->em->flush();

                $this->activityLog->log('school_event.created', [
                    'entityId' => $event->getId()->toRfc4122(),
                    'name'     => $event->getName(),
                ]);

                $this->addFlash('success', $this->t('school_event.flash.created'));

                return $this->redirectToRoute('app_calendar', ['tab' => 'events']);
            }
        }

        return $this->render('school_event/new.html.twig', [
            'centre'           => $centre,
            'errors'           => $errors,
            'values'           => $values,
            'availableGroups'  => $this->groups->findByActiveYearOfCentreOrderedByName($centre),
            'selectedGroupIds' => $selectedGroupIds,
        ]);
    }

    #[Route('/{eventId}/editar', name: 'app_events_edit', methods: ['GET', 'POST'])]
    public function edit(string $eventId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);
        $this->denyIfViewingPastYear($centre);

        $event = $this->findManageable($centre, $eventId);

        $errors = [];
        $values = [
            'date'        => $event->getDate()->format('Y-m-d'),
            'start_time'  => $event->getStartTime()->format('H:i'),
            'end_time'    => $event->getEndTime()->format('H:i'),
            'name'        => $event->getName(),
            'description' => $event->getDescription() ?? '',
            'url'         => $event->getUrl() ?? '',
            'scope'       => $event->isGeneral() ? 'general' : 'restricted',
        ];
        $selectedGroupIds = array_map(
            static fn (Group $g): string => $g->getId()->toRfc4122(),
            $event->getGroups()->toArray(),
        );

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_school_event_' . $eventId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values           = $this->valuesFromRequest($request);
            $selectedGroupIds = $request->request->all('groups');
            $errors           = $this->validate($values, $selectedGroupIds);

            if (empty($errors)) {
                $this->applyValues($event, $values, $centre, $selectedGroupIds);
                $this->em->flush();

                $this->activityLog->log('school_event.updated', [
                    'entityId' => $event->getId()->toRfc4122(),
                    'name'     => $event->getName(),
                ]);

                $this->addFlash('success', $this->t('school_event.flash.saved'));

                return $this->redirectToRoute('app_calendar', ['tab' => 'events']);
            }
        }

        return $this->render('school_event/edit.html.twig', [
            'centre'           => $centre,
            'event'            => $event,
            'errors'           => $errors,
            'values'           => $values,
            'availableGroups'  => $this->groups->findByActiveYearOfCentreOrderedByName($centre),
            'selectedGroupIds' => $selectedGroupIds,
        ]);
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

        return $this->redirectToRoute('app_calendar', ['tab' => 'events']);
    }

    /**
     * El evento del curso visualizado que quien publica puede gestionar: la dirección y los
     * administradores, cualquiera; el resto del profesorado (con el ajuste «Todos»), solo los que
     * ve, es decir, los generales y los de sus grupos.
     */
    private function findManageable(EducationalCentre $centre, string $eventId): SchoolEvent
    {
        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$this->isGranted(EducationalCentreVoter::SECTION, $centre) && $user instanceof Teacher) {
            $event = $this->events->findVisibleByAcademicYearAndId($user, $year, $eventId);
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
        ];
    }

    /**
     * @param array<string, string> $values
     * @param array<array-key, mixed> $selectedGroupIds
     *
     * @return array<string, string>
     */
    private function validate(array $values, array $selectedGroupIds): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = $this->t('school_event.error.name_required');
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
     */
    private function applyValues(SchoolEvent $event, array $values, EducationalCentre $centre, array $selectedGroupIds): void
    {
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
            ->setGeneral($general);

        foreach ($event->getGroups()->toArray() as $group) {
            $event->removeGroup($group);
        }

        foreach ($selected as $group) {
            $event->addGroup($group);
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
