<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssueAttachment;
use App\Entity\IssueChange;
use App\Entity\IssueObservation;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\LocationOption;
use App\Entity\ResourceBooking;
use App\Entity\Teacher;
use App\Repository\IssueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Altas y cambios de las incidencias, con sus reglas y su historial: cada cambio de un campo (el estado incluido) queda
 * anotado con el valor antiguo y el nuevo tal como se veían entonces (nombres, no identificadores).
 *
 * Reglas de estado: una incidencia recién abierta no tiene prioridad y solo puede pasar a «en proceso» cuando se le asigna
 * una; los otros estados no la exigen. Cerrarla por duplicidad exige indicar la incidencia original. Reabrir una cerrada la
 * devuelve a «abierta» o «en proceso» y borra su fecha de cierre.
 */
class IssueManager
{
    /** Campos del historial. */
    public const FIELD_CREATED     = 'created';
    public const FIELD_STATUS      = 'status';
    public const FIELD_PRIORITY    = 'priority';
    public const FIELD_TYPE        = 'type';
    public const FIELD_LOCATION    = 'location';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_DUPLICATE   = 'duplicate_of';
    public const FIELD_ATTACHMENT  = 'attachment';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly IssueRepository $issues,
        private readonly IssueDeadlines $deadlines,
        private readonly ActivityLogService $activityLog,
        private readonly IssueNotifier $notifier,
    ) {}

    /**
     * @param list<IssueAttachment> $attachments
     */
    public function create(
        EducationalCentre $centre,
        AcademicYear $year,
        Teacher $reporter,
        IssueType $type,
        ?LocationOption $location,
        ?ResourceBooking $booking,
        string $description,
        array $attachments = [],
    ): Issue {
        $now   = $this->clock->now();
        $issue = (new Issue($now))
            ->setEducationalCentre($centre)
            ->setAcademicYear($year)
            ->setNumber($this->issues->nextNumber($centre))
            ->setType($type)
            ->setLocation($booking === null ? $location : null)
            ->setBooking($booking)
            ->setReporter($reporter)
            ->setDescription($description);
        $this->em->persist($issue);

        $this->record($issue, $reporter, self::FIELD_CREATED, null, $type->getPath());
        foreach ($attachments as $attachment) {
            $issue->addAttachment($attachment);
            $this->record($issue, $reporter, self::FIELD_ATTACHMENT, null, $attachment->getFilename());
        }
        $this->em->flush();

        $this->activityLog->log('issue.created', [
            'entityId' => $issue->getId()->toRfc4122(),
            'number'   => $issue->getNumber(),
            'type'     => $type->getPath(),
        ]);
        $this->notifier->created($issue);

        return $issue;
    }

    public function addObservation(Issue $issue, Teacher $author, string $content): IssueObservation
    {
        $now         = $this->clock->now();
        $observation = new IssueObservation($issue, $author, $content, $now);
        $issue->addObservation($observation);
        $issue->touch($now);
        $this->em->persist($observation);
        $this->em->flush();

        $this->activityLog->log('issue.observed', ['entityId' => $issue->getId()->toRfc4122(), 'number' => $issue->getNumber()]);
        $this->notifier->observation($issue, $author, $content);

        return $observation;
    }

    /** Cambia la descripción (lo ocurrido). Devuelve si cambió. */
    public function updateDescription(Issue $issue, Teacher $actor, string $description): bool
    {
        if ($description === $issue->getDescription()) {
            return false;
        }

        $this->record($issue, $actor, self::FIELD_DESCRIPTION, $issue->getDescription(), $description);
        $issue->setDescription($description)->touch($this->clock->now());
        $this->em->flush();

        $this->activityLog->log('issue.updated', ['entityId' => $issue->getId()->toRfc4122(), 'number' => $issue->getNumber()]);
        $this->notifier->changed($issue, $actor, self::FIELD_DESCRIPTION);

        return true;
    }

    /**
     * @param list<IssueAttachment> $attachments
     */
    public function addAttachments(Issue $issue, Teacher $actor, array $attachments): void
    {
        if ($attachments === []) {
            return;
        }
        foreach ($attachments as $attachment) {
            $issue->addAttachment($attachment);
            $this->record($issue, $actor, self::FIELD_ATTACHMENT, null, $attachment->getFilename());
        }
        $issue->touch($this->clock->now());
        $this->em->flush();
    }

    public function removeAttachment(Issue $issue, Teacher $actor, IssueAttachment $attachment): void
    {
        $this->record($issue, $actor, self::FIELD_ATTACHMENT, $attachment->getFilename(), null);
        $issue->removeAttachment($attachment);
        $issue->touch($this->clock->now());
        $this->em->flush();
    }

    public function changeType(Issue $issue, Teacher $actor, IssueType $type): bool
    {
        if ($type->getId()->equals($issue->getType()->getId())) {
            return false;
        }

        $oldPath = $issue->getType()->getPath();
        $this->record($issue, $actor, self::FIELD_TYPE, $oldPath, $type->getPath());
        $issue->setType($type)->touch($this->clock->now());
        $this->em->flush();

        $this->activityLog->log('issue.updated', ['entityId' => $issue->getId()->toRfc4122(), 'number' => $issue->getNumber()]);
        $this->notifier->changed($issue, $actor, self::FIELD_TYPE, $oldPath, $type->getPath());

        return true;
    }

    public function changeLocation(Issue $issue, Teacher $actor, ?LocationOption $location): bool
    {
        $current = $issue->getLocation();
        if (($current?->getId()->toRfc4122()) === ($location?->getId()->toRfc4122())) {
            return false;
        }

        $this->record($issue, $actor, self::FIELD_LOCATION, $current?->getName(), $location?->getName());
        $issue->setLocation($location)->touch($this->clock->now());
        $this->em->flush();
        $this->notifier->changed($issue, $actor, self::FIELD_LOCATION, $current?->getName(), $location?->getName());

        return true;
    }

    /**
     * Asigna (o cambia) la prioridad y recalcula las fechas límite desde ahora. Quitarla no se permite en una incidencia
     * en proceso. Devuelve la clave de traducción del error, o null si todo fue bien (incluso si no cambió nada).
     */
    public function setPriority(Issue $issue, Teacher $actor, ?IssuePriority $priority): ?string
    {
        $current = $issue->getPriority();
        if (($current?->getId()->toRfc4122()) === ($priority?->getId()->toRfc4122())) {
            return null;
        }
        if ($priority === null && $issue->getStatus() === IssueStatus::InProgress) {
            return 'issue.error.priority_required';
        }

        $now = $this->clock->now();
        $this->record($issue, $actor, self::FIELD_PRIORITY, $current?->getName(), $priority?->getName());
        $issue->setPriority($priority)->touch($now);
        $this->deadlines->apply($issue, $priority, $now);
        $this->em->flush();

        $this->activityLog->log('issue.prioritized', ['entityId' => $issue->getId()->toRfc4122(), 'number' => $issue->getNumber()]);
        $this->notifier->priority($issue, $actor, $priority);

        return null;
    }

    /**
     * Cambia el estado. Devuelve la clave de traducción del error (no se cambia nada) o null si fue bien.
     */
    public function transition(Issue $issue, Teacher $actor, IssueStatus $to, ?Issue $duplicateOf = null): ?string
    {
        $from = $issue->getStatus();
        if ($to === $from && ($to !== IssueStatus::Duplicate || ($duplicateOf?->getId()->toRfc4122()) === ($issue->getDuplicateOf()?->getId()->toRfc4122()))) {
            return null;
        }
        if ($to === IssueStatus::InProgress && $issue->getPriority() === null) {
            return 'issue.error.priority_required';
        }
        if ($to === IssueStatus::Duplicate && !$this->validDuplicate($issue, $duplicateOf)) {
            return 'issue.error.duplicate_invalid';
        }

        $now = $this->clock->now();
        if ($to !== $from) {
            $this->record($issue, $actor, self::FIELD_STATUS, $from->value, $to->value);
        }

        $previousDuplicate = $issue->getDuplicateOf();
        $newDuplicate      = $to === IssueStatus::Duplicate ? $duplicateOf : null;
        if (($previousDuplicate?->getId()->toRfc4122()) !== ($newDuplicate?->getId()->toRfc4122())) {
            $this->record($issue, $actor, self::FIELD_DUPLICATE, $previousDuplicate !== null ? '#' . $previousDuplicate->getNumber() : null, $newDuplicate !== null ? '#' . $newDuplicate->getNumber() : null);
        }

        $issue->setStatus($to)->setDuplicateOf($newDuplicate)->setClosedAt($to->isClosed() ? $now : null)->touch($now);
        $this->em->flush();

        $this->activityLog->log('issue.status_changed', [
            'entityId' => $issue->getId()->toRfc4122(),
            'number'   => $issue->getNumber(),
            'status'   => $to->value,
        ]);
        if ($to !== $from) {
            $this->notifier->status($issue, $actor);
        }

        return null;
    }

    /**
     * Elimina la incidencia con sus observaciones, adjuntos e historial. Las que se cerraron por duplicidad de esta se
     * quedan cerradas, sin enlace a la original. Queda constancia en el registro de actividad.
     */
    public function delete(Issue $issue): void
    {
        $this->activityLog->log('issue.deleted', [
            'entityId' => $issue->getId()->toRfc4122(),
            'number'   => $issue->getNumber(),
            'type'     => $issue->getType()->getPath(),
        ]);
        $this->em->remove($issue);
        $this->em->flush();
    }

    /** La original ha de ser de este centro, otra incidencia y no estar cerrada como duplicada de esta. */
    private function validDuplicate(Issue $issue, ?Issue $original): bool
    {
        return $original !== null
            && !$original->getId()->equals($issue->getId())
            && $original->getEducationalCentre()->getId()->equals($issue->getEducationalCentre()->getId())
            && !($original->getDuplicateOf()?->getId()->equals($issue->getId()) ?? false);
    }

    private function record(Issue $issue, ?Teacher $author, string $field, ?string $old, ?string $new): void
    {
        $issue->addChange(new IssueChange($issue, $author, $field, $old, $new, $this->clock->now()));
    }
}
