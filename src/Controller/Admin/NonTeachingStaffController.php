<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\IssueTypeRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\ProfileMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Personal no docente de un centro: cuentas de la tabla global de docentes con la marca «no docente» que se asocian
 * al centro (sin curso académico) y solo acceden a Incidencias. Se incorporan por identificador o correo; si no
 * existen, se registran con contraseña inicial (con cambio forzado) o con un enlace de invitación por correo.
 */
#[Route('/centro/{centreId}/personal-no-docente')]
class NonTeachingStaffController extends AbstractController
{
    use TranslatorTrait;

    /** Validez del enlace de invitación. */
    private const INVITATION_VALIDITY = '+7 days';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly TeacherRepository $teachers,
        private readonly IssueTypeRepository $issueTypes,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TranslatorInterface $translator,
        private readonly ProfileMailer $mailer,
        private readonly ClockInterface $clock,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('', name: 'app_non_teaching_index', methods: ['GET'])]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/non_teaching/index.html.twig', [
            'centre' => $centre,
            'staff'  => $this->teachers->findNonTeachingByCentre($centre),
        ]);
    }

    #[Route('/añadir', name: 'app_non_teaching_add', methods: ['POST'])]
    public function add(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        if (!$this->isCsrfTokenValid('add_non_teaching', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $identifier = trim($request->request->getString('identifier'));
        if ($identifier === '') {
            return $this->redirectToRoute('app_non_teaching_index', ['centreId' => $centre->getId()]);
        }

        $account = $this->teachers->findByUsernameOrEmail($identifier);
        if ($account === null) {
            return $this->redirectToRoute('app_non_teaching_register', ['centreId' => $centre->getId(), 'identifier' => $identifier]);
        }

        // Las cuentas docentes y las de administración global no se convierten en personal no docente.
        if (!$account->isNonTeaching()) {
            $this->addFlash('error', $this->t($account->isAdmin() ? 'non_teaching.flash.is_admin' : 'non_teaching.flash.is_teacher'));

            return $this->redirectToRoute('app_non_teaching_index', ['centreId' => $centre->getId()]);
        }

        if ($centre->getNonTeachingStaff()->contains($account)) {
            $this->addFlash('info', $this->t('non_teaching.flash.already'));
        } else {
            $centre->addNonTeachingStaff($account);
            $this->em->flush();
            $this->activityLog->log('non_teaching.added', ['entityId' => $account->getId()->toRfc4122(), 'username' => $account->getUsername()]);
            $this->addFlash('success', $this->t('non_teaching.flash.added'));
        }

        return $this->redirectToRoute('app_non_teaching_index', ['centreId' => $centre->getId()]);
    }

    #[Route('/registrar', name: 'app_non_teaching_register', methods: ['GET', 'POST'])]
    public function register(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        $identifier = trim($request->query->getString('identifier'));
        $values     = [
            'first_name' => '',
            'last_name'  => '',
            'username'   => $identifier !== '' && !str_contains($identifier, '@') ? $identifier : '',
            'email'      => str_contains($identifier, '@') ? $identifier : '',
            'password'   => '',
        ];
        $mode   = 'password';
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register_non_teaching', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            foreach (array_keys($values) as $field) {
                $values[$field] = $field === 'password' ? $request->request->getString($field) : trim($request->request->getString($field));
            }
            $mode   = $request->request->getString('mode') === 'invitation' ? 'invitation' : 'password';
            $errors = $this->validate($values, $mode === 'password', true);
            if (!isset($errors['username']) && $this->teachers->findByUsername($values['username']) !== null) {
                $errors['username'] = $this->t('non_teaching.error.username_duplicate');
            }
            if (!isset($errors['email']) && $this->teachers->findByUsernameOrEmail($values['email']) !== null) {
                $errors['email'] = $this->t('non_teaching.error.email_duplicate');
            }

            if ($errors === []) {
                $account = (new Teacher(new PersonName($values['first_name'], $values['last_name'])))
                    ->setUsername($values['username'])
                    ->setEmail($values['email'])
                    ->setActive(true)
                    ->setExternal(false)
                    ->setNonTeaching(true);

                if ($mode === 'password') {
                    $account->setPassword($this->hasher->hashPassword($account, $values['password']))->setForcePasswordChange(true);
                } else {
                    // Contraseña inutilizable hasta que la persona establezca la suya con el enlace.
                    $account->setPassword($this->hasher->hashPassword($account, bin2hex(random_bytes(32))));
                    $token = bin2hex(random_bytes(32));
                    $account->setPasswordResetToken($token)
                        ->setPasswordResetTokenExpiresAt($this->clock->now()->modify(self::INVITATION_VALIDITY));
                }

                $centre->addNonTeachingStaff($account);
                $this->em->persist($account);
                $this->em->flush();
                $this->activityLog->log('non_teaching.created', ['entityId' => $account->getId()->toRfc4122(), 'username' => $account->getUsername()]);

                if ($mode === 'invitation') {
                    $this->mailer->sendInvitation($account, $token, $centre);
                    $this->addFlash('success', $this->t('non_teaching.flash.invited'));
                } else {
                    $this->addFlash('success', $this->t('non_teaching.flash.created'));
                }

                return $this->redirectToRoute('app_non_teaching_index', ['centreId' => $centre->getId()]);
            }
        }

        return $this->render('admin/non_teaching/form.html.twig', [
            'centre'     => $centre,
            'account'    => null,
            'values'     => $values,
            'mode'       => $mode,
            'active'     => true,
            'errors'     => $errors,
            'identifier' => $identifier,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_non_teaching_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre  = $this->requireCentre($centreId);
        $account = $this->requireMember($centre, $id);

        $values = [
            'first_name' => $account->getName()->getFirstName(),
            'last_name'  => $account->getName()->getLastName(),
            'username'   => $account->getUsername(),
            'email'      => (string) $account->getEmail(),
            'password'   => '',
        ];
        $active = $account->isActive();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_non_teaching', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            foreach (array_keys($values) as $field) {
                $values[$field] = $field === 'password' ? $request->request->getString($field) : trim($request->request->getString($field));
            }
            $active = $request->request->getString('active') === '1';
            $errors = $this->validate($values, false, true);
            $other  = isset($errors['username']) ? null : $this->teachers->findByUsername($values['username']);
            if ($other !== null && $other->getId()->toRfc4122() !== $account->getId()->toRfc4122()) {
                $errors['username'] = $this->t('non_teaching.error.username_duplicate');
            }
            $other = isset($errors['email']) ? null : $this->teachers->findByUsernameOrEmail($values['email']);
            if ($other !== null && $other->getId()->toRfc4122() !== $account->getId()->toRfc4122()) {
                $errors['email'] = $this->t('non_teaching.error.email_duplicate');
            }

            if ($errors === []) {
                $account->setName(new PersonName($values['first_name'], $values['last_name']))
                    ->setUsername($values['username'])
                    ->setEmail($values['email'])
                    ->setActive($active);
                if ($values['password'] !== '') {
                    $account->setPassword($this->hasher->hashPassword($account, $values['password']))->setForcePasswordChange(true);
                }
                $this->em->flush();
                $this->activityLog->log('non_teaching.updated', ['entityId' => $account->getId()->toRfc4122(), 'username' => $account->getUsername()]);
                $this->addFlash('success', $this->t('non_teaching.flash.saved'));

                return $this->redirectToRoute('app_non_teaching_index', ['centreId' => $centre->getId()]);
            }
        }

        return $this->render('admin/non_teaching/form.html.twig', [
            'centre'     => $centre,
            'account'    => $account,
            'values'     => $values,
            'mode'       => 'password',
            'active'     => $active,
            'errors'     => $errors,
            'identifier' => '',
        ]);
    }

    #[Route('/{id}/invitar', name: 'app_non_teaching_invite', methods: ['POST'])]
    public function invite(string $centreId, string $id, Request $request): Response
    {
        $centre  = $this->requireCentre($centreId);
        $account = $this->requireMember($centre, $id);
        if (!$this->isCsrfTokenValid('invite_non_teaching_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($account->getEmail() === null || $account->getEmail() === '') {
            $this->addFlash('error', $this->t('non_teaching.error.email_required'));
        } else {
            $token = bin2hex(random_bytes(32));
            $account->setPasswordResetToken($token)
                ->setPasswordResetTokenExpiresAt($this->clock->now()->modify(self::INVITATION_VALIDITY));
            $this->em->flush();
            $this->mailer->sendInvitation($account, $token, $centre);
            $this->addFlash('success', $this->t('non_teaching.flash.invited'));
        }

        return $this->redirectToRoute('app_non_teaching_index', ['centreId' => $centre->getId()]);
    }

    #[Route('/{id}/quitar', name: 'app_non_teaching_remove', methods: ['POST'])]
    public function remove(string $centreId, string $id, Request $request): Response
    {
        $centre  = $this->requireCentre($centreId);
        $account = $this->requireMember($centre, $id);
        if (!$this->isCsrfTokenValid('remove_non_teaching_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Deja de ser responsable de los tipos de incidencia del centro; sus incidencias se conservan.
        foreach ($this->issueTypes->findByCentre($centre) as $type) {
            $type->removeManager($account);
        }
        $centre->removeNonTeachingStaff($account);
        $this->em->flush();
        $this->activityLog->log('non_teaching.removed', ['entityId' => $account->getId()->toRfc4122(), 'username' => $account->getUsername()]);
        $this->addFlash('success', $this->t('non_teaching.flash.removed'));

        return $this->redirectToRoute('app_non_teaching_index', ['centreId' => $centre->getId()]);
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

    private function requireMember(EducationalCentre $centre, string $id): Teacher
    {
        foreach ($centre->getNonTeachingStaff() as $member) {
            if ($member->getId()->toRfc4122() === $id) {
                return $member;
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function validate(array $values, bool $passwordRequired, bool $emailRequired): array
    {
        $errors = [];
        if ($values['first_name'] === '') {
            $errors['first_name'] = $this->t('teacher.error.first_name_required');
        }
        if ($values['last_name'] === '') {
            $errors['last_name'] = $this->t('teacher.error.last_name_required');
        }
        if ($values['username'] === '') {
            $errors['username'] = $this->t('teacher.error.username_required');
        }
        if ($values['email'] === '' && $emailRequired) {
            $errors['email'] = $this->t('non_teaching.error.email_required');
        } elseif ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $this->t('teacher.error.email_invalid');
        }
        if ($passwordRequired && $values['password'] === '') {
            $errors['password'] = $this->t('teacher.error.password_required');
        }

        return $errors;
    }
}
