<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DeactivateExpiredDailyNotesMessage;
use App\Repository\DailyNoteRepository;
use App\Repository\DailyNoteTypeRepository;
use App\Repository\EducationalCentreRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DeactivateExpiredDailyNotesHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly DailyNoteTypeRepository $types,
        private readonly DailyNoteRepository $notes,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(DeactivateExpiredDailyNotesMessage $message): void
    {
        foreach ($this->centres->findAll() as $centre) {
            foreach ($this->types->findByCentreOrdered($centre) as $type) {
                $days = $type->getExpiryDays();
                if ($days <= 0) {
                    continue;
                }

                $cutoff = $this->clock->now()->modify("-{$days} days");
                $this->notes->deactivateExpiredByType($type, $cutoff);
            }
        }
    }
}
