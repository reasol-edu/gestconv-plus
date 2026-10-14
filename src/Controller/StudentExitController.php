<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Repository\ExitReasonRepository;
use App\Repository\GroupRepository;
use App\Repository\StudentExitRepository;
use App\Repository\StudentPriorityPassRepository;
use App\Repository\StudentRepository;
use App\Security\Voter\StudentExitVoter;
use App\Service\ActivityLogService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/salidas')]
class StudentExitController extends AbstractController
{
    use PastYearGuardTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly StudentExitRepository $exits,
        private readonly ExitReasonRepository $reasons,
        private readonly GroupRepository $groups,
        private readonly StudentRepository $students,
        private readonly StudentPriorityPassRepository $passes,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('', name: 'app_exits_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $canManage = $this->isGranted(StudentExitVoter::MANAGE, $centre);
        $tab       = $canManage && $request->query->getString('tab') === 'history' ? 'history' : 'current';

        return $this->render('exit/index.html.twig', [
            'centre'    => $centre,
            'tab'       => $tab,
            'canManage' => $canManage,
            'canCreate' => $centre->getActiveAcademicYear() !== null
                && !$this->tenantContext->isViewingNonActiveYear($centre),
        ]);
    }

    /**
     * Autorizar una salida en tres pasos, cada uno con su propia dirección para que el botón
     * «atrás» del móvil funcione: grupo → estudiante (`?groupId=`) → motivo (`&studentId=`).
     */
    #[Route('/nueva', name: 'app_exits_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyIfViewingPastYear($centre);

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(StudentExitVoter::AUTHORIZE, $centre);

        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            return $this->redirectToRoute('app_exits_index');
        }

        $groupId   = trim($request->query->getString('groupId'));
        $studentId = trim($request->query->getString('studentId'));

        $group = $groupId !== '' ? $this->groups->findByIdAndCentre($groupId, $centre) : null;
        if ($group !== null && $group->getAcademicYear() !== $year) {
            $group = null;
        }

        // ── Paso 1: grupo ───────────────────────────────────────────────────────
        if ($group === null) {
            $mine     = $this->groups->findTaughtOrTutoredInYear($year, $user);
            $mineIds  = array_map(static fn (Group $g): string => $g->getId()->toRfc4122(), $mine);
            $others   = array_values(array_filter(
                $this->groups->findByActiveYearOfCentreOrderedByName($centre, $year),
                static fn (Group $g): bool => !in_array($g->getId()->toRfc4122(), $mineIds, true),
            ));

            return $this->render('exit/new_group.html.twig', [
                'centre' => $centre,
                'mine'   => $mine,
                'others' => $others,
            ]);
        }

        $groupStudents = $this->students->findByGroupOrdered($group);
        $student       = null;
        foreach ($groupStudents as $candidate) {
            if ($studentId !== '' && $candidate->getId()->toRfc4122() === $studentId) {
                $student = $candidate;
                break;
            }
        }

        $currentExits = $this->exits->findCurrent($year);
        $outNow       = [];
        foreach ($currentExits as $exit) {
            $outNow[$exit->getStudent()->getId()->toRfc4122()] = true;
        }

        // ── Paso 2: estudiante ──────────────────────────────────────────────────
        if ($student === null) {
            return $this->render('exit/new_student.html.twig', [
                'centre'   => $centre,
                'group'    => $group,
                'students' => $groupStudents,
                'passes'   => $this->passes->findByStudentsIndexed($groupStudents, $year),
                'outNow'   => $outNow,
            ]);
        }

        // ── Paso 3: motivo, observaciones y confirmación ────────────────────────
        $reasons  = $this->reasons->findActiveByCentreOrdered($centre);
        $errors   = [];
        $formData = ['reason_id' => '', 'observations' => ''];
        $alreadyOut = isset($outNow[$student->getId()->toRfc4122()]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_exit', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $formData['reason_id']     = trim($request->request->getString('reason_id'));
            $formData['observations']  = trim($request->request->getString('observations'));

            $reason = $this->reasons->findById($formData['reason_id']);
            if ($reason === null || $reason->getEducationalCentre() !== $centre || !$reason->isActive()) {
                $errors['reason'] = $this->t('exit.error.no_reason');
            }
            if ($alreadyOut) {
                $errors['student'] = $this->t('exit.error.already_out');
            }

            if ($errors === [] && $reason !== null) {
                $exit = (new StudentExit())
                    ->setAcademicYear($year)
                    ->setStudent($student)
                    ->setGroup($group)
                    ->setReason($reason)
                    ->setObservations($formData['observations'] !== '' ? $formData['observations'] : null)
                    ->setAuthorizedBy($user);

                $this->em->persist($exit);
                $this->em->flush();

                $this->activityLog->log('student_exit.created', [
                    'entityId'  => $exit->getId()->toRfc4122(),
                    'studentId' => $student->getId()->toRfc4122(),
                    'groupId'   => $group->getId()->toRfc4122(),
                    'reasonId'  => $reason->getId()->toRfc4122(),
                ]);

                $this->addFlash('success', $this->translator->trans('exit.flash.created', ['%student%' => $student->getName()->full()], 'admin'));

                return $this->redirectToRoute('app_exits_index');
            }
        }

        return $this->render('exit/new_reason.html.twig', [
            'centre'     => $centre,
            'group'      => $group,
            'student'    => $student,
            'pass'       => $this->passes->findOneByStudentAndYear($student, $year),
            'reasons'    => $reasons,
            'errors'     => $errors,
            'formData'   => $formData,
            'alreadyOut' => $alreadyOut,
        ]);
    }
}
