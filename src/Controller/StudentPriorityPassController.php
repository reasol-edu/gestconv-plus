<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Student;
use App\Entity\StudentPriorityPass;
use App\Entity\Teacher;
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

/**
 * Concede, modifica o retira el «pase prioritario» de un estudiante en el curso activo.
 * Solo el equipo directivo y la administración.
 */
#[Route('/alumnado/{id}/pase-prioritario')]
class StudentPriorityPassController extends AbstractController
{
    use PastYearGuardTrait;
    use TranslatorTrait;

    private const DESCRIPTION_MAX_LENGTH = 1000;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly StudentRepository $students,
        private readonly StudentPriorityPassRepository $passes,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('', name: 'app_students_priority_pass_save', methods: ['POST'])]
    public function save(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$student, $year] = $this->resolve($id, $centre);
        $this->checkToken($request, 'priority_pass_' . $id);

        $description = trim($request->request->getString('description'));
        if ($description === '' || mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            $this->addFlash('error', $this->t('priority_pass.flash.invalid'));

            return $this->redirectToRoute('app_students_show', ['id' => $id]);
        }

        $user = $this->getUser();
        $pass = $this->passes->findOneByStudentAndYear($student, $year);
        $new  = $pass === null;
        if ($pass === null) {
            $pass = (new StudentPriorityPass())->setStudent($student)->setAcademicYear($year);
            $this->em->persist($pass);
        }
        $pass->setDescription($description)->setGrantedBy($user instanceof Teacher ? $user : null);
        $this->em->flush();

        $this->activityLog->log($new ? 'priority_pass.granted' : 'priority_pass.updated', [
            'entityId'  => $pass->getId()->toRfc4122(),
            'studentId' => $student->getId()->toRfc4122(),
        ]);
        $this->addFlash('success', $this->t($new ? 'priority_pass.flash.granted' : 'priority_pass.flash.updated'));

        return $this->redirectToRoute('app_students_show', ['id' => $id]);
    }

    #[Route('/eliminar', name: 'app_students_priority_pass_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$student, $year] = $this->resolve($id, $centre);
        $this->checkToken($request, 'priority_pass_' . $id);

        $pass = $this->passes->findOneByStudentAndYear($student, $year);
        if ($pass !== null) {
            $passId = $pass->getId()->toRfc4122();
            $this->em->remove($pass);
            $this->em->flush();

            $this->activityLog->log('priority_pass.revoked', [
                'entityId'  => $passId,
                'studentId' => $student->getId()->toRfc4122(),
            ]);
            $this->addFlash('success', $this->t('priority_pass.flash.revoked'));
        }

        return $this->redirectToRoute('app_students_show', ['id' => $id]);
    }

    /** @return array{0: Student, 1: AcademicYear} */
    private function resolve(string $id, EducationalCentre $centre): array
    {
        $this->denyIfViewingPastYear($centre);
        $this->denyAccessUnlessGranted(StudentExitVoter::MANAGE, $centre);

        $student = $this->students->findById($id);
        if ($student === null || !$this->students->belongsToCentre($student, $centre)) {
            throw $this->createNotFoundException();
        }

        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        return [$student, $year];
    }

    private function checkToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
