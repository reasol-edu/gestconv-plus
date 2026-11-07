<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Service\ActivityLogService;
use App\Service\NonTeachingStaffManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestión global del personal no docente por la administración de la plataforma: alta (con contraseña inicial o con enlace de
 * invitación), edición, centros a los que pertenece, reenvío de la invitación y eliminación. Se lista junto a los docentes, en
 * un bloque aparte, en «Usuarios».
 */
#[Route('/admin/usuarios/personal-no-docente')]
#[IsGranted('ROLE_ADMIN')]
class NonTeachingAdminController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeacherRepository $teachers,
        private readonly EducationalCentreRepository $centres,
        private readonly NonTeachingStaffManager $manager,
        private readonly ActivityLogService $activityLog,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/nuevo', name: 'app_admin_non_teaching_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $values   = $this->emptyValues();
        $mode     = 'password';
        $selected = [];
        $errors   = [];
        $all      = array_values($this->centres->findAllOrderedByName());

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_non_teaching_admin', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values   = $this->readValues($request);
            $mode     = $request->request->getString('mode') === 'invitation' ? 'invitation' : 'password';
            $selected = $this->readCentres($request, $all);
            $errors   = $this->manager->validate($values, $mode === 'password');
            $errors  += $this->duplicates($values, null);

            if ($errors === []) {
                [$account, $token] = $this->manager->register($values, $mode === 'password' ? $values['password'] : null, $selected);
                $this->em->flush();
                $this->activityLog->log('non_teaching.created', ['entityId' => $account->getId()->toRfc4122(), 'username' => $account->getUsername()]);

                if ($token !== null) {
                    $this->manager->sendInvitation($account, $token, $this->centreNames($selected));
                    $this->addFlash('success', $this->t('non_teaching.flash.invited'));
                } else {
                    $this->addFlash('success', $this->t('non_teaching.flash.created'));
                }

                return $this->redirectToRoute('app_admin_teachers_index');
            }
        }

        return $this->render('admin/non_teaching_admin/form.html.twig', [
            'account'  => null,
            'values'   => $values,
            'mode'     => $mode,
            'active'   => true,
            'errors'   => $errors,
            'centres'  => $all,
            'selected' => $this->ids($selected),
        ]);
    }

    #[Route('/{id}/editar', name: 'app_admin_non_teaching_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $account = $this->requireNonTeaching($id);
        $all     = array_values($this->centres->findAllOrderedByName());

        $values   = [
            'first_name' => $account->getName()->getFirstName(),
            'last_name'  => $account->getName()->getLastName(),
            'username'   => $account->getUsername(),
            'email'      => (string) $account->getEmail(),
            'password'   => '',
        ];
        $active   = $account->isActive();
        $selected = array_values(array_filter($all, static fn (EducationalCentre $c): bool => $c->getNonTeachingStaff()->contains($account)));
        $errors   = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_non_teaching_admin', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values   = $this->readValues($request);
            $active   = $request->request->getString('active') === '1';
            $selected = $this->readCentres($request, $all);
            $errors   = $this->manager->validate($values, false);
            $errors  += $this->duplicates($values, $account);

            if ($errors === []) {
                $account->setName(new PersonName($values['first_name'], $values['last_name']))
                    ->setUsername($values['username'])
                    ->setEmail($values['email'])
                    ->setActive($active);
                if ($values['password'] !== '') {
                    $this->manager->changePassword($account, $values['password']);
                }
                $this->manager->syncCentres($account, $selected, $all);
                $this->em->flush();
                $this->activityLog->log('non_teaching.updated', ['entityId' => $account->getId()->toRfc4122(), 'username' => $account->getUsername()]);
                $this->addFlash('success', $this->t('non_teaching.flash.saved'));

                return $this->redirectToRoute('app_admin_teachers_index');
            }
        }

        return $this->render('admin/non_teaching_admin/form.html.twig', [
            'account'  => $account,
            'values'   => $values,
            'mode'     => 'password',
            'active'   => $active,
            'errors'   => $errors,
            'centres'  => $all,
            'selected' => $this->ids($selected),
        ]);
    }

    #[Route('/{id}/invitar', name: 'app_admin_non_teaching_invite', methods: ['POST'])]
    public function invite(string $id, Request $request): Response
    {
        $account = $this->requireNonTeaching($id);
        if (!$this->isCsrfTokenValid('invite_non_teaching_admin_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($account->getEmail() === null || $account->getEmail() === '') {
            $this->addFlash('error', $this->t('non_teaching.error.email_required'));
        } else {
            $token = $this->manager->newInvitationToken($account);
            $this->em->flush();
            $this->manager->sendInvitation($account, $token, $this->centreNames($this->centresOf($account)));
            $this->addFlash('success', $this->t('non_teaching.flash.invited'));
        }

        return $this->redirectToRoute('app_admin_teachers_index');
    }

    #[Route('/{id}/eliminar', name: 'app_admin_non_teaching_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $account = $this->requireNonTeaching($id);
        if (!$this->isCsrfTokenValid('delete_non_teaching_admin_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityId = $account->getId()->toRfc4122();
        $username = $account->getUsername();

        try {
            foreach ($this->centresOf($account) as $centre) {
                $this->manager->removeFromCentre($account, $centre);
            }
            $this->em->remove($account);
            $this->em->flush();
            $this->activityLog->log('non_teaching.deleted', ['entityId' => $entityId, 'username' => $username]);
            $this->addFlash('success', $this->t('non_teaching.flash.deleted'));
        } catch (\Exception) {
            $this->addFlash('error', $this->t('non_teaching.flash.delete_error'));
        }

        return $this->redirectToRoute('app_admin_teachers_index');
    }

    private function requireNonTeaching(string $id): Teacher
    {
        $account = $this->teachers->findById($id);
        if ($account === null || !$account->isNonTeaching()) {
            throw $this->createNotFoundException();
        }

        return $account;
    }

    /** @return array{first_name: string, last_name: string, username: string, email: string, password: string} */
    private function emptyValues(): array
    {
        return ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'password' => ''];
    }

    /** @return array{first_name: string, last_name: string, username: string, email: string, password: string} */
    private function readValues(Request $request): array
    {
        return [
            'first_name' => trim($request->request->getString('first_name')),
            'last_name'  => trim($request->request->getString('last_name')),
            'username'   => trim($request->request->getString('username')),
            'email'      => trim($request->request->getString('email')),
            'password'   => $request->request->getString('password'),
        ];
    }

    /**
     * Los centros marcados en el formulario (solo los que existen).
     *
     * @param list<EducationalCentre> $all
     * @return list<EducationalCentre>
     */
    private function readCentres(Request $request, array $all): array
    {
        $ids = array_filter($request->request->all('centres'), 'is_string');

        return array_values(array_filter($all, static fn (EducationalCentre $c): bool => \in_array($c->getId()->toRfc4122(), $ids, true)));
    }

    /**
     * @param array{first_name: string, last_name: string, username: string, email: string, password: string} $values
     * @return array<string, string>
     */
    private function duplicates(array $values, ?Teacher $self): array
    {
        $errors = [];
        $other  = $values['username'] === '' ? null : $this->teachers->findByUsername($values['username']);
        if ($other !== null && ($self === null || $other->getId()->toRfc4122() !== $self->getId()->toRfc4122())) {
            $errors['username'] = $this->t('non_teaching.error.username_duplicate');
        }
        $other = $values['email'] === '' ? null : $this->teachers->findByUsernameOrEmail($values['email']);
        if ($other !== null && ($self === null || $other->getId()->toRfc4122() !== $self->getId()->toRfc4122())) {
            $errors['email'] = $this->t('non_teaching.error.email_duplicate');
        }

        return $errors;
    }

    /** @return list<EducationalCentre> los centros a los que pertenece la cuenta */
    private function centresOf(Teacher $account): array
    {
        return array_values(array_filter(
            $this->centres->findAllWithNonTeachingStaff(),
            static fn (EducationalCentre $c): bool => $c->getNonTeachingStaff()->contains($account),
        ));
    }

    /** @param list<EducationalCentre> $centres */
    private function centreNames(array $centres): string
    {
        return $centres === []
            ? $this->translator->trans('non_teaching.invitation_no_centre', [], 'admin')
            : implode(', ', array_map(static fn (EducationalCentre $c): string => $c->getName(), $centres));
    }

    /**
     * @param list<EducationalCentre> $centres
     * @return list<string>
     */
    private function ids(array $centres): array
    {
        return array_map(static fn (EducationalCentre $c): string => $c->getId()->toRfc4122(), $centres);
    }
}
