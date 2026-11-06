<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\IssueTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Operaciones sobre las cuentas de personal no docente que comparten la gestión del centro y la de la administración de la
 * plataforma: validar los datos, registrar la cuenta (con contraseña inicial o con enlace de invitación), enviar la invitación
 * y asociarla a los centros o quitarla de ellos.
 */
class NonTeachingStaffManager
{
    /** Validez del enlace de invitación. */
    private const INVITATION_VALIDITY = '+7 days';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
        private readonly IssueTypeRepository $issueTypes,
        private readonly ProfileMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Errores de los datos de una cuenta, por campo (vacío si son válidos).
     *
     * @param array{first_name: string, last_name: string, username: string, email: string, password: string} $values
     * @return array<string, string>
     */
    public function validate(array $values, bool $passwordRequired): array
    {
        $t      = fn (string $key): string => $this->translator->trans($key, [], 'admin');
        $errors = [];

        if ($values['first_name'] === '') {
            $errors['first_name'] = $t('teacher.error.first_name_required');
        }
        if ($values['last_name'] === '') {
            $errors['last_name'] = $t('teacher.error.last_name_required');
        }
        if ($values['username'] === '') {
            $errors['username'] = $t('teacher.error.username_required');
        }
        if ($values['email'] === '') {
            $errors['email'] = $t('non_teaching.error.email_required');
        } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $t('teacher.error.email_invalid');
        }
        if ($passwordRequired && $values['password'] === '') {
            $errors['password'] = $t('teacher.error.password_required');
        }

        return $errors;
    }

    /**
     * Crea la cuenta y la asocia a los centros (persiste y deja el flush a quien llama). Con contraseña, se le fuerza a cambiarla
     * en su primer acceso; sin ella, la cuenta queda con una contraseña inutilizable y un token para el enlace de invitación,
     * que se devuelve (con la invitación sin enviar: ver {@see self::sendInvitation()}).
     *
     * @param array{first_name: string, last_name: string, username: string, email: string} $values
     * @param list<EducationalCentre> $centres
     * @return array{0: Teacher, 1: ?string} la cuenta y el token de invitación (null si se fijó una contraseña)
     */
    public function register(array $values, ?string $password, array $centres): array
    {
        $account = (new Teacher(new PersonName($values['first_name'], $values['last_name'])))
            ->setUsername($values['username'])
            ->setEmail($values['email'])
            ->setActive(true)
            ->setExternal(false)
            ->setNonTeaching(true);

        $token = null;
        if ($password !== null) {
            $account->setPassword($this->hasher->hashPassword($account, $password))->setForcePasswordChange(true);
        } else {
            $account->setPassword($this->hasher->hashPassword($account, bin2hex(random_bytes(32))));
            $token = $this->newInvitationToken($account);
        }

        foreach ($centres as $centre) {
            $centre->addNonTeachingStaff($account);
        }
        $this->em->persist($account);

        return [$account, $token];
    }

    /** Cambia la contraseña de la cuenta y le fuerza a cambiarla en su próximo acceso. No hace flush. */
    public function changePassword(Teacher $account, string $password): void
    {
        $account->setPassword($this->hasher->hashPassword($account, $password))->setForcePasswordChange(true);
    }

    /** Genera un enlace de invitación nuevo (válido 7 días) y devuelve su token. No hace flush. */
    public function newInvitationToken(Teacher $account): string
    {
        $token = bin2hex(random_bytes(32));
        $account->setPasswordResetToken($token)
            ->setPasswordResetTokenExpiresAt($this->clock->now()->modify(self::INVITATION_VALIDITY));

        return $token;
    }

    /** Envía la invitación; en el correo aparecen los centros de la persona (o, si no tiene, el nombre de la aplicación). */
    public function sendInvitation(Teacher $account, string $token, string $centreNames): void
    {
        $this->mailer->sendInvitation($account, $token, $centreNames);
    }

    /**
     * Deja a la cuenta asociada exactamente a esos centros (de entre $allCentres, los que se pueden elegir). No hace flush.
     *
     * @param list<EducationalCentre> $selected
     * @param list<EducationalCentre> $allCentres
     */
    public function syncCentres(Teacher $account, array $selected, array $allCentres): void
    {
        $wanted = [];
        foreach ($selected as $centre) {
            $wanted[$centre->getId()->toRfc4122()] = true;
        }

        foreach ($allCentres as $centre) {
            $member = $centre->getNonTeachingStaff()->contains($account);
            $want   = isset($wanted[$centre->getId()->toRfc4122()]);
            if ($want && !$member) {
                $centre->addNonTeachingStaff($account);
            } elseif (!$want && $member) {
                $this->removeFromCentre($account, $centre);
            }
        }
    }

    /**
     * Quita a la persona del centro y de la responsabilidad de sus tipos de incidencia; sus incidencias se conservan. No hace flush.
     */
    public function removeFromCentre(Teacher $account, EducationalCentre $centre): void
    {
        foreach ($this->issueTypes->findByCentre($centre) as $type) {
            $type->removeManager($account);
        }
        $centre->removeNonTeachingStaff($account);
    }
}
