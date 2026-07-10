<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LocationOptionCategory;
use App\Repository\EducationalCentreRepository;
use App\Repository\LocationOptionCategoryRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/ubicaciones/categorias')]
class LocationOptionCategoryController extends AbstractController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['name'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly LocationOptionCategoryRepository $categories,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly EntityChangeTracker $changeTracker,
    ) {}

    #[Route('/nueva', name: 'app_centre_location_option_categories_create', methods: ['GET', 'POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_location_category_' . $centreId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name = trim($request->request->getString('name'));

            if ($name !== '') {
                $position = $this->categories->countByCentre($centre);

                $category = (new LocationOptionCategory())
                    ->setEducationalCentre($centre)
                    ->setName($name)
                    ->setPosition($position);

                $this->em->persist($category);
                $this->em->flush();

                $this->activityLog->log('location_option_category.created', [
                    'entityId' => $category->getId()->toRfc4122(),
                    'name'     => $category->getName(),
                ]);

                $this->addFlash('success', $this->t('location_category.flash.created'));

                return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
            }
        }

        return $this->render('admin/location_option_category/edit.html.twig', [
            'centre'   => $centre,
            'category' => null,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_centre_location_option_categories_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $category = $this->categories->findById($id);
        if ($category === null || $category->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_location_category_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name = trim($request->request->getString('name'));

            if ($name !== '') {
                $before = $this->changeTracker->snapshot($category, self::LOGGED_FIELDS);

                $category->setName($name);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $category, self::LOGGED_FIELDS);
                if ($changes !== []) {
                    $this->activityLog->log('location_option_category.updated', [
                        'entityId' => $category->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('location_category.flash.updated'));
            }

            return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/location_option_category/edit.html.twig', [
            'centre'   => $centre,
            'category' => $category,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_centre_location_option_categories_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('delete_location_category_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $category = $this->categories->findById($id);
        if ($category === null || $category->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $entityId = $category->getId()->toRfc4122();
        $name     = $category->getName();

        $this->em->remove($category);
        $this->em->flush();

        foreach ($this->categories->findByCentreOrdered($centre) as $pos => $cat) {
            $cat->setPosition($pos);
        }
        $this->em->flush();

        $this->activityLog->log('location_option_category.deleted', [
            'entityId' => $entityId,
            'name'     => $name,
        ]);

        $this->addFlash('success', $this->t('location_category.flash.deleted'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/subir', name: 'app_centre_location_option_categories_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_location_category_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $all = $this->categories->findByCentreOrdered($centre);
        foreach ($all as $i => $cat) {
            if ($cat->getId()->toRfc4122() === $id && $i > 0) {
                $prev = $all[$i - 1];
                $posC = $cat->getPosition();
                $cat->setPosition($prev->getPosition());
                $prev->setPosition($posC);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('location_category.flash.moved'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/bajar', name: 'app_centre_location_option_categories_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_location_category_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $all   = $this->categories->findByCentreOrdered($centre);
        $count = count($all);
        foreach ($all as $i => $cat) {
            if ($cat->getId()->toRfc4122() === $id && $i < $count - 1) {
                $next = $all[$i + 1];
                $posC = $cat->getPosition();
                $cat->setPosition($next->getPosition());
                $next->setPosition($posC);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('location_category.flash.moved'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
