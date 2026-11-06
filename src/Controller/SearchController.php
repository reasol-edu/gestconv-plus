<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\Teacher;
use App\Repository\IssueRepository;
use App\Repository\StudentRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\IssueVoter;
use App\Service\IssueAccess;
use App\Service\IssueListFilter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_TEACHER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StudentRepository $studentRepository,
        private readonly TeacherRepository $teacherRepository,
        private readonly IssueRepository $issues,
        private readonly IssueAccess $issueAccess,
        private readonly TranslatorInterface $translator,
        #[Target('search')]
        private readonly RateLimiterFactoryInterface $searchLimiter,
    ) {}

    #[Route('/buscar', name: 'app_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $limiter = $this->searchLimiter->create($this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?? 'anon');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['groups' => []], JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->json(['groups' => []]);
        }

        $q = trim($request->query->getString('q'));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return $this->json(['groups' => []]);
        }

        $groups = [];
        $user   = $this->getUser();
        $viewer = $user instanceof Teacher ? $user : null;

        $year     = $centre->getActiveAcademicYear();
        $students = $year !== null ? $this->studentRepository->searchByCentre($centre, $q, 30) : [];
        if ($students !== []) {
            $taught = [];
            $other  = [];

            foreach ($students as $s) {
                $groupNames = [];
                $isTaught   = false;

                foreach ($s->getGroups() as $group) {
                    if ($group->getAcademicYear() !== $year) {
                        continue;
                    }
                    $groupNames[] = $group->getName();
                    if ($viewer !== null && ($group->getTeachers()->contains($viewer) || $group->getTutors()->contains($viewer))) {
                        $isTaught = true;
                    }
                }

                $entry = [
                    'label'    => $s->getName()->getLastName() . ', ' . $s->getName()->getFirstName(),
                    'sublabel' => implode(', ', $groupNames),
                    'url'      => $this->generateUrl('app_students_show', [
                        'id' => $s->getId()->toRfc4122(),
                    ]),
                ];

                if ($isTaught) {
                    $taught[] = $entry;
                } else {
                    $other[] = $entry;
                }
            }

            if ($taught !== []) {
                $groups['students_taught'] = array_slice($taught, 0, 5);
            }
            if ($other !== []) {
                $groups['students_other'] = array_slice($other, 0, 5);
            }
        }

        if ($this->isGranted('educational_centre.section', $centre)) {
            $year = $this->tenantContext->getViewYear($centre);
            if ($year !== null) {
                $teachers = $this->teacherRepository->searchByAcademicYear($year, $q);
                if ($teachers !== []) {
                    $groups['teachers'] = array_map(fn ($t) => [
                        'label'    => $t->getName()->getLastName() . ', ' . $t->getName()->getFirstName(),
                        'sublabel' => $t->getUsername(),
                        'url'      => $this->generateUrl('app_centre_teachers_subjects', [
                            'centreId'  => $centre->getId()->toRfc4122(),
                            'teacherId' => $t->getId()->toRfc4122(),
                        ]),
                    ], $teachers);
                }
            }
        }

        // Incidencias: las que el docente registró o gestiona, por número, texto, tipo, dependencia o persona.
        if ($viewer !== null && $this->isGranted(IssueVoter::CREATE, $centre)) {
            $found = $this->issues->createListQuery(
                $centre,
                $viewer,
                $this->issueAccess->isManagement($viewer, $centre),
                $this->issueAccess->managedTypeIds($viewer, $centre),
                new IssueListFilter(search: $q, sort: 'created', sortDir: 'desc'),
            )->setMaxResults(5)->getResult();
            /** @var list<Issue> $found */
            if ($found !== []) {
                $groups['issues'] = array_map(fn (Issue $issue): array => [
                    'label'    => '#' . $issue->getNumber() . ' · ' . $issue->getType()->getName(),
                    'sublabel' => $this->translator->trans('issue.status.' . $issue->getStatus()->value, [], 'admin') . ' · ' . $issue->getReporter()->getName()->full(),
                    'url'      => $this->generateUrl('app_issues_show', ['id' => $issue->getId()->toRfc4122()]),
                ], $found);
            }
        }

        return $this->json(['groups' => $groups]);
    }
}
