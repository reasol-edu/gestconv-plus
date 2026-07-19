<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SanctionTaskAttachment;
use App\Message\PurgeSanctionTaskAttachmentsMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\SanctionTaskRepository;
use App\Service\AppSettingsInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class PurgeSanctionTaskAttachmentsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly SanctionTaskRepository $tasks,
        private readonly AppSettingsInterface $settings,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PurgeSanctionTaskAttachmentsMessage $message): void
    {
        $now = new \DateTimeImmutable();

        foreach ($this->centres->findAll() as $centre) {
            $days = $this->settings->getForCentre('sanction_tasks.attachment_retention_days', $centre);
            if (!is_int($days) || $days <= 0) {
                continue;
            }

            $cutoff = new \DateTimeImmutable("-{$days} days");

            foreach ($this->tasks->findWithAttachmentsOlderThan($centre, $cutoff) as $task) {
                $notes = '';
                foreach ($task->getAttachments()->toArray() as $attachment) {
                    $notes .= $this->purgeNote($attachment, $now);
                    $task->removeAttachment($attachment);
                }

                if ($notes !== '') {
                    $task->setDescription($task->getDescription() . $notes);
                }
            }
        }

        $this->em->flush();
    }

    private function purgeNote(SanctionTaskAttachment $attachment, \DateTimeImmutable $now): string
    {
        $message = $this->translator->trans('sanction_task.attachment_purged_note', [
            '%filename%' => htmlspecialchars($attachment->getFilename(), ENT_QUOTES),
            '%size%'     => $this->formatSize($attachment->getSize()),
            '%datetime%' => $now->format('d/m/Y H:i'),
        ], 'admin');

        return '<p><em>' . $message . '</em></p>';
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return round($kb) . ' KB';
        }

        return round($kb / 1024, 1) . ' MB';
    }
}
