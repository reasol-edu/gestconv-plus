<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\Teacher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A quién avisar de lo que ocurre en una incidencia: el alta, a sus responsables; el resto de cambios, a quien la registró
 * y a sus responsables, salvo a quien los hace. Cada aviso respeta los ajustes de recepción (global, centro y docente) y
 * el resumen diario ({@see IncidentEmailNotifier}).
 */
class IssueNotifier
{
    /** Longitud máxima de la observación que se copia en el correo. */
    private const OBSERVATION_EXCERPT = 600;

    public function __construct(
        private readonly IncidentEmailNotifier $emails,
        private readonly IssueAccess $access,
        private readonly TranslatorInterface $translator,
    ) {}

    public function created(Issue $issue): void
    {
        foreach ($this->recipients($issue, $issue->getReporter(), false) as $recipient) {
            $this->emails->issueNotice($recipient, $issue, 'issue_created', $issue->getReporter());
        }
    }

    public function status(Issue $issue, Teacher $actor): void
    {
        $detail = $this->translator->trans('issue.status.' . $issue->getStatus()->value, [], 'admin');
        foreach ($this->recipients($issue, $actor) as $recipient) {
            $this->emails->issueNotice($recipient, $issue, 'issue_status', $actor, $detail);
        }
    }

    public function priority(Issue $issue, Teacher $actor, ?IssuePriority $priority): void
    {
        $detail = $priority?->getName() ?? $this->translator->trans('issue.priority.none', [], 'admin');
        foreach ($this->recipients($issue, $actor) as $recipient) {
            $this->emails->issueNotice($recipient, $issue, 'issue_priority', $actor, $detail);
        }
    }

    public function observation(Issue $issue, Teacher $actor, string $content): void
    {
        $detail = mb_strlen($content) > self::OBSERVATION_EXCERPT ? mb_substr($content, 0, self::OBSERVATION_EXCERPT) . '…' : $content;
        foreach ($this->recipients($issue, $actor) as $recipient) {
            $this->emails->issueNotice($recipient, $issue, 'issue_observation', $actor, $detail);
        }
    }

    /** Cambio de la descripción, el tipo o la dependencia. `$field` es la clave del historial. */
    public function changed(Issue $issue, Teacher $actor, string $field, ?string $old = null, ?string $new = null): void
    {
        $label  = $this->translator->trans('issue.history.field.' . $field, [], 'admin');
        $detail = $old === null && $new === null ? $label : sprintf('%s: %s → %s', $label, $old ?? '—', $new ?? '—');
        foreach ($this->recipients($issue, $actor) as $recipient) {
            $this->emails->issueNotice($recipient, $issue, 'issue_changed', $actor, $detail);
        }
    }

    /**
     * Se ha asignado (o reasignado, o desasignado) la incidencia. A la nueva persona asignada le llega el aviso de asignación;
     * a las demás (quien la registró, sus responsables y la persona asignada hasta entonces), el de cambio de datos.
     */
    public function assigned(Issue $issue, Teacher $actor, ?Teacher $previous): void
    {
        $assignee = $issue->getAssignee();
        $label    = $this->translator->trans('issue.history.field.assignee', [], 'admin');
        $detail   = sprintf('%s: %s → %s', $label, $previous?->getName()->full() ?? '—', $assignee?->getName()->full() ?? '—');

        if ($assignee !== null && !$assignee->getId()->equals($actor->getId())) {
            $this->emails->issueNotice($assignee, $issue, 'issue_assigned', $actor);
        }
        foreach ($this->recipients($issue, $actor, true, $previous) as $recipient) {
            if ($assignee === null || !$recipient->getId()->equals($assignee->getId())) {
                $this->emails->issueNotice($recipient, $issue, 'issue_changed', $actor, $detail);
            }
        }
    }

    /**
     * Quien la registró (si `$includeReporter`), la persona asignada, sus responsables y, si se indica, otra persona más
     * (la asignada hasta entonces), sin repetir y sin quien hace el cambio.
     *
     * @return list<Teacher>
     */
    private function recipients(Issue $issue, Teacher $actor, bool $includeReporter = true, ?Teacher $also = null): array
    {
        /** @var array<string, Teacher> $recipients */
        $recipients = [];
        if ($includeReporter) {
            $recipients[$issue->getReporter()->getId()->toRfc4122()] = $issue->getReporter();
        }
        foreach ([$issue->getAssignee(), $also, ...$this->access->responsibles($issue)] as $person) {
            if ($person !== null) {
                $recipients[$person->getId()->toRfc4122()] = $person;
            }
        }
        unset($recipients[$actor->getId()->toRfc4122()]);

        return array_values($recipients);
    }
}
