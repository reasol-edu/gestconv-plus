<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Entity\ResourceCategory;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\ResourceBookingRepository;
use App\Repository\ResourceCategoryRepository;
use App\Repository\SharedResourceRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\SchoolEventTypeColor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Catálogo de recursos compartidos del centro (Centro educativo › Recursos compartidos): categorías con sus
 * responsables y los recursos de cada una. Solo la dirección y los administradores.
 */
#[Route('/centro/{centreId}/recursos')]
class ResourceCatalogController extends AbstractController
{
    use TranslatorTrait;

    /** @var list<string> */
    private const LOGGED_CATEGORY_FIELDS = ['name', 'color', 'active', 'restricted', 'requiresApproval', 'maxAdvanceDays'];

    /** @var list<string> */
    private const LOGGED_RESOURCE_FIELDS = ['name', 'description', 'location', 'active'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly ResourceCategoryRepository $categories,
        private readonly SharedResourceRepository $resources,
        private readonly ResourceBookingRepository $bookings,
        private readonly TeacherRepository $teachers,
        private readonly EntityChangeTracker $changeTracker,
    ) {}

    #[Route('', name: 'app_centre_resources_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        $resourcesByCategory = [];
        foreach ($this->resources->findByCentreOrdered($centre) as $resource) {
            $resourcesByCategory[$resource->getCategory()->getId()->toRfc4122()][] = $resource;
        }

        return $this->render('admin/resource_catalog/index.html.twig', [
            'centre'              => $centre,
            'categories'          => $this->categories->findByCentreOrdered($centre),
            'resourcesByCategory' => $resourcesByCategory,
        ]);
    }

    #[Route('/categorias/nueva', name: 'app_centre_resources_category_create', methods: ['POST'])]
    public function createCategory(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'new_resource_category_' . $centreId);

        $name = trim($request->request->getString('name'));
        if ($name === '') {
            $this->addFlash('error', $this->t('resource_catalog.flash.invalid'));

            return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
        }

        $category = (new ResourceCategory())
            ->setEducationalCentre($centre)
            ->setName(mb_substr($name, 0, 200))
            ->setColor(SchoolEventTypeColor::normalize($request->request->getString('color')));

        $this->em->persist($category);
        $this->em->flush();

        $this->activityLog->log('resource_category.created', [
            'entityId' => $category->getId()->toRfc4122(),
            'name'     => $category->getName(),
        ]);

        // Los responsables y el resto de opciones se fijan al editarla.
        $this->addFlash('success', $this->t('resource_catalog.flash.category_created'));

        return $this->redirectToRoute('app_centre_resources_category_edit', ['centreId' => $centreId, 'id' => $category->getId()->toRfc4122()]);
    }

    #[Route('/categorias/{id}/editar', name: 'app_centre_resources_category_edit', methods: ['GET', 'POST'])]
    public function editCategory(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $category = $this->requireCategory($id, $centre);
        $teachers = $this->selectableTeachers($centre, $category);

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_resource_category_' . $id);

            $name = trim($request->request->getString('name'));
            if ($name === '') {
                $this->addFlash('error', $this->t('resource_catalog.flash.invalid'));

                return $this->redirectToRoute('app_centre_resources_category_edit', ['centreId' => $centreId, 'id' => $id]);
            }

            $before = $this->changeTracker->snapshot($category, self::LOGGED_CATEGORY_FIELDS);

            $advance = trim($request->request->getString('max_advance_days'));
            $category
                ->setName(mb_substr($name, 0, 200))
                ->setColor($request->request->getBoolean('no_color') ? null : SchoolEventTypeColor::normalize($request->request->getString('color')))
                ->setActive($request->request->getBoolean('active'))
                ->setRestricted($request->request->getBoolean('restricted'))
                ->setRequiresApproval($request->request->getBoolean('requires_approval'))
                ->setMaxAdvanceDays($advance !== '' && ctype_digit($advance) ? min((int) $advance, 365) : null);

            // Los responsables han de ser docentes del curso (o quienes ya lo eran de esta categoría); lo demás se ignora.
            $selected = [];
            foreach ($request->request->all('managers') as $teacherId) {
                if (is_string($teacherId) && isset($teachers[$teacherId])) {
                    $selected[$teacherId] = $teachers[$teacherId];
                }
            }
            foreach ($category->getManagers()->toArray() as $manager) {
                if (!isset($selected[$manager->getId()->toRfc4122()])) {
                    $category->removeManager($manager);
                }
            }
            foreach ($selected as $manager) {
                $category->addManager($manager);
            }

            $this->em->flush();

            $changes = $this->changeTracker->diff($before, $category, self::LOGGED_CATEGORY_FIELDS);
            $this->activityLog->log('resource_category.updated', [
                'entityId' => $id,
                'changes'  => $changes,
                'managers' => array_values(array_map(static fn (Teacher $t): string => $t->getName()->full(), $selected)),
            ]);

            $this->addFlash('success', $this->t('resource_catalog.flash.category_updated'));

            return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/resource_catalog/category_edit.html.twig', [
            'centre'             => $centre,
            'category'           => $category,
            'availableTeachers'  => array_values($teachers),
            'selectedManagerIds' => array_map(static fn (Teacher $t): string => $t->getId()->toRfc4122(), $category->getManagers()->toArray()),
        ]);
    }

    #[Route('/categorias/{id}/eliminar', name: 'app_centre_resources_category_delete', methods: ['POST'])]
    public function deleteCategory(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $category = $this->requireCategory($id, $centre);
        $this->checkCsrf($request, 'delete_resource_category_' . $id);

        if ($this->bookings->countByCategory($category) > 0) {
            $this->addFlash('error', $this->t('resource_catalog.flash.category_in_use'));

            return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
        }

        $name = $category->getName();
        $this->em->remove($category);
        $this->em->flush();

        $this->activityLog->log('resource_category.deleted', ['entityId' => $id, 'name' => $name]);
        $this->addFlash('success', $this->t('resource_catalog.flash.category_deleted'));

        return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
    }

    #[Route('/categorias/{id}/recursos/nuevo', name: 'app_centre_resources_resource_create', methods: ['POST'])]
    public function createResource(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $category = $this->requireCategory($id, $centre);
        $this->checkCsrf($request, 'new_shared_resource_' . $id);

        $name = trim($request->request->getString('name'));
        if ($name === '') {
            $this->addFlash('error', $this->t('resource_catalog.flash.invalid'));

            return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
        }

        $resource = (new SharedResource())
            ->setCategory($category)
            ->setName(mb_substr($name, 0, 200))
            ->setLocation($this->nullIfEmpty($request->request->getString('location'), 255));

        $this->em->persist($resource);
        $this->em->flush();

        $this->activityLog->log('shared_resource.created', [
            'entityId' => $resource->getId()->toRfc4122(),
            'name'     => $resource->getName(),
            'category' => $category->getName(),
        ]);
        $this->addFlash('success', $this->t('resource_catalog.flash.resource_created'));

        return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
    }

    #[Route('/recursos/{id}/editar', name: 'app_centre_resources_resource_edit', methods: ['GET', 'POST'])]
    public function editResource(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $resource = $this->requireResource($id, $centre);

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_shared_resource_' . $id);

            $name = trim($request->request->getString('name'));
            if ($name === '') {
                $this->addFlash('error', $this->t('resource_catalog.flash.invalid'));

                return $this->redirectToRoute('app_centre_resources_resource_edit', ['centreId' => $centreId, 'id' => $id]);
            }

            $before = $this->changeTracker->snapshot($resource, self::LOGGED_RESOURCE_FIELDS);

            // Se puede cambiar de categoría, pero solo a otra del mismo centro.
            $newCategory = $this->categories->findByIdAndCentre($request->request->getString('category'), $centre);

            $resource
                ->setName(mb_substr($name, 0, 200))
                ->setDescription($this->nullIfEmpty($request->request->getString('description'), 2000))
                ->setLocation($this->nullIfEmpty($request->request->getString('location'), 255))
                ->setActive($request->request->getBoolean('active'));
            if ($newCategory !== null) {
                $resource->setCategory($newCategory);
            }
            $this->em->flush();

            $changes = $this->changeTracker->diff($before, $resource, self::LOGGED_RESOURCE_FIELDS);
            $this->activityLog->log('shared_resource.updated', ['entityId' => $id, 'changes' => $changes]);
            $this->addFlash('success', $this->t('resource_catalog.flash.resource_updated'));

            return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/resource_catalog/resource_edit.html.twig', [
            'centre'     => $centre,
            'resource'   => $resource,
            'categories' => $this->categories->findByCentreOrdered($centre),
        ]);
    }

    #[Route('/recursos/{id}/eliminar', name: 'app_centre_resources_resource_delete', methods: ['POST'])]
    public function deleteResource(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $resource = $this->requireResource($id, $centre);
        $this->checkCsrf($request, 'delete_shared_resource_' . $id);

        if ($this->bookings->countByResource($resource) > 0) {
            $this->addFlash('error', $this->t('resource_catalog.flash.resource_in_use'));

            return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
        }

        $name = $resource->getName();
        $this->em->remove($resource);
        $this->em->flush();

        $this->activityLog->log('shared_resource.deleted', ['entityId' => $id, 'name' => $name]);
        $this->addFlash('success', $this->t('resource_catalog.flash.resource_deleted'));

        return $this->redirectToRoute('app_centre_resources_index', ['centreId' => $centreId]);
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }

    private function requireCategory(string $id, EducationalCentre $centre): ResourceCategory
    {
        $category = $this->categories->findByIdAndCentre($id, $centre);
        if ($category === null) {
            throw $this->createNotFoundException();
        }

        return $category;
    }

    private function requireResource(string $id, EducationalCentre $centre): SharedResource
    {
        $resource = $this->resources->findByIdAndCentre($id, $centre);
        if ($resource === null) {
            throw $this->createNotFoundException();
        }

        return $resource;
    }

    private function checkCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function nullIfEmpty(string $value, int $maxLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * Docentes que pueden ser responsables: los del curso activo del centro y, al editar, quienes ya lo son aunque
     * hayan dejado el curso. Indexados por id.
     *
     * @return array<string, Teacher>
     */
    private function selectableTeachers(EducationalCentre $centre, ResourceCategory $category): array
    {
        $year     = $centre->getActiveAcademicYear();
        $teachers = [];
        foreach ($year !== null ? $this->teachers->findByAcademicYearOrderedByName($year) : [] as $teacher) {
            $teachers[$teacher->getId()->toRfc4122()] = $teacher;
        }
        foreach ($category->getManagers() as $manager) {
            $teachers[$manager->getId()->toRfc4122()] ??= $manager;
        }

        return $teachers;
    }
}
