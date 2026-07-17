<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ActivityAttachment;
use App\Message\PurgeActivityAttachmentsMessage;
use App\Repository\ActivityRepository;
use App\Repository\EducationalCentreRepository;
use App\Service\AppSettingsInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class PurgeActivityAttachmentsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly ActivityRepository $activities,
        private readonly AppSettingsInterface $settings,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PurgeActivityAttachmentsMessage $message): void
    {
        $now = new \DateTimeImmutable();

        foreach ($this->centres->findAll() as $centre) {
            $days = $this->settings->getForCentre('absences.attachment_retention_days', $centre);
            if (!is_int($days) || $days <= 0) {
                continue;
            }

            $cutoff = new \DateTimeImmutable("-{$days} days");

            foreach ($this->activities->findWithAttachmentsOlderThan($centre, $cutoff) as $activity) {
                $notes = '';
                foreach ($activity->getAttachments()->toArray() as $attachment) {
                    $notes .= $this->purgeNote($attachment, $now);
                    $activity->removeAttachment($attachment);
                }

                if ($notes !== '') {
                    $activity->setDescription($activity->getDescription() . $notes);
                }
            }
        }

        $this->em->flush();
    }

    private function purgeNote(ActivityAttachment $attachment, \DateTimeImmutable $now): string
    {
        $message = $this->translator->trans('activity.attachment_purged_note', [
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
