<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/perfiles')]
class CentreProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly TeacherRepository $teachers,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_centre_profiles_index')]
    public function index(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        /** @var Teacher[] $selectedCommitteeMembers */
        $selectedCommitteeMembers = $centre->getCommitteeMembers()->toArray();
        /** @var Teacher[] $selectedCounselors */
        $selectedCounselors = $centre->getCounselors()->toArray();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_centre_profiles_' . $centreId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $submittedCommitteeIds = $this->submittedIds($request, 'committee_members');
            $submittedCounselorIds = $this->submittedIds($request, 'counselors');

            foreach ($centre->getCommitteeMembers()->toArray() as $teacher) {
                $centre->removeCommitteeMember($teacher);
            }
            foreach ($submittedCommitteeIds as $teacherId) {
                $teacher = $this->teachers->findById($teacherId);
                if ($teacher !== null) {
                    $centre->addCommitteeMember($teacher);
                }
            }

            foreach ($centre->getCounselors()->toArray() as $teacher) {
                $centre->removeCounselor($teacher);
            }
            foreach ($submittedCounselorIds as $teacherId) {
                $teacher = $this->teachers->findById($teacherId);
                if ($teacher !== null) {
                    $centre->addCounselor($teacher);
                }
            }

            $this->em->flush();

            $this->addFlash('success', $this->t('centre_profiles.flash.saved'));

            return $this->redirectToRoute('app_centre_profiles_index', ['centreId' => $centre->getId()]);
        }

        return $this->render('admin/centre_profile/index.html.twig', [
            'centre'                   => $centre,
            'selectedCommitteeMembers' => $selectedCommitteeMembers,
            'selectedCounselors'       => $selectedCounselors,
        ]);
    }

    /**
     * @return list<string>
     */
    private function submittedIds(Request $request, string $field): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $v): string => \is_string($v) ? $v : '',
                $request->request->all($field)
            ),
            static fn (string $v): bool => $v !== ''
        ));
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
