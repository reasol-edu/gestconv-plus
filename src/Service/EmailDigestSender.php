<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\EmailDigestItem;
use App\Entity\Teacher;
use App\Repository\EmailDigestItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends the daily digest emails: one per teacher and centre, at the hour each teacher has configured
 * ("notifications.email_digest_hour", 7 by default), with the lines queued since the previous digest.
 *
 * Teachers who chose "notifications.email_delivery = daily_digest" get every notification here; the
 * rest only receive the three daily reminders (upcoming prescriptions of reports and sanctions and
 * pending sanction tasks), so they get one email a day instead of three.
 */
final class EmailDigestSender
{
    private const DEFAULT_HOUR = 7;

    /** Lines already sent are kept this many days; lines that could not be sent are dropped after twice as long. */
    private const KEEP_SENT_DAYS = 7;

    private const KEEP_UNSENT_DAYS = 14;

    public function __construct(
        private readonly EmailDigestItemRepository $items,
        private readonly IncidentEmailNotifier $notifier,
        private readonly AppSettingsInterface $settings,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Sends every digest whose time has come. A digest is due once the current time has passed today's
     * digest hour of its recipient; it contains the lines queued up to that hour, so a late run (for
     * instance after the worker was stopped) still sends exactly one digest a day.
     *
     * @param bool $ignoreHour send all pending lines now, whatever each recipient's hour is
     * @return int number of digest emails sent
     */
    public function sendDue(bool $ignoreHour = false): int
    {
        $now = $this->clock->now();

        /** @var array<string, array{teacher: Teacher, centre: EducationalCentre, items: list<EmailDigestItem>}> $groups */
        $groups = [];
        foreach ($this->items->findUnsent() as $item) {
            $key = $item->getRecipient()->getId()->toRfc4122() . '|' . $item->getEducationalCentre()->getId()->toRfc4122();

            $groups[$key] ??= ['teacher' => $item->getRecipient(), 'centre' => $item->getEducationalCentre(), 'items' => []];
            $groups[$key]['items'][] = $item;
        }

        $sent = 0;
        foreach ($groups as $group) {
            $dueAt = $now->setTime($this->hourFor($group['teacher'], $group['centre']), 0);
            if (!$ignoreHour && $now < $dueAt) {
                continue;
            }

            $included = $ignoreHour
                ? $group['items']
                : array_values(array_filter($group['items'], static fn (EmailDigestItem $i): bool => $i->getCreatedAt() <= $dueAt));
            if ($included === []) {
                continue;
            }

            if (!$this->notifier->sendDigest($group['teacher'], $group['centre'], $included)) {
                continue; // the lines stay pending and are retried on the next run
            }

            foreach ($included as $item) {
                $item->markSent($now);
            }
            $this->em->flush();
            ++$sent;
        }

        $this->items->purge(
            $now->modify('-' . self::KEEP_SENT_DAYS . ' days'),
            $now->modify('-' . self::KEEP_UNSENT_DAYS . ' days'),
        );

        return $sent;
    }

    private function hourFor(Teacher $teacher, EducationalCentre $centre): int
    {
        $hour = $this->settings->getForTeacherInCentre('notifications.email_digest_hour', $teacher, $centre);

        return is_int($hour) && $hour >= 0 && $hour <= 23 ? $hour : self::DEFAULT_HOUR;
    }
}
