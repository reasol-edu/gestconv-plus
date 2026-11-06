<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\Teacher;
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\IssueAccess;
use App\Service\TeacherYearRoles;
use App\Service\TenantContextInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Incidencias.
 *
 *  - CREATE (sujeto EducationalCentre): registrar una incidencia. Cualquier docente del curso y la dirección.
 *  - VIEW (sujeto Issue): ver una incidencia y observar en ella (añadir observaciones y adjuntos): quien la registró, los
 *    responsables de su tipo (propios y heredados) y la dirección.
 *  - EDIT (sujeto Issue): cambiar la descripción: quien la registró, mientras esté abierta, y quien la gestiona.
 *  - MANAGE (sujeto Issue): gestionarla (tipo, dependencia, prioridad, estado, reabrirla): responsables y dirección.
 *
 * Con el módulo desactivado en el centro, nadie.
 *
 * @extends Voter<string, EducationalCentre|Issue>
 */
final class IssueVoter extends Voter
{
    public const CREATE = 'issue.create';
    public const VIEW   = 'issue.view';
    public const EDIT   = 'issue.edit';
    public const MANAGE = 'issue.manage';

    public function __construct(
        private readonly IssueAccess $access,
        private readonly FeatureToggle $features,
        private readonly TeacherYearRoles $roles,
        private readonly TenantContextInterface $tenant,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::CREATE                       => $subject instanceof EducationalCentre,
            self::VIEW, self::EDIT, self::MANAGE => $subject instanceof Issue,
            default                            => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        if ($subject instanceof EducationalCentre) {
            if (!$this->features->isEnabledForCentre(Feature::Issues, $subject)) {
                return false;
            }
            if ($this->access->isManagement($user, $subject)) {
                return true;
            }
            $year = $this->tenant->getViewYear($subject);

            return $year !== null && $this->roles->belongsToYear($user, $year);
        }

        /** @var Issue $subject */
        if (!$this->features->isEnabledForCentre(Feature::Issues, $subject->getEducationalCentre())) {
            return false;
        }

        return match ($attribute) {
            self::MANAGE => $this->access->manages($user, $subject),
            self::EDIT   => $this->access->manages($user, $subject) || ($this->access->isReporter($user, $subject) && $subject->isOpen()),
            default      => $this->access->canView($user, $subject),
        };
    }
}
