<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\EmailDigestItem;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\Teacher;
use App\Entity\TeacherSettingValue;
use App\Message\IssueOverdueReminderMessage;
use App\MessageHandler\IssueOverdueReminderHandler;
use App\Service\IssueManager;
use App\Service\IssuePrioritySeeder;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** Avisos de las incidencias: a quién llegan, cuándo se omiten y el recordatorio diario de las vencidas. */
class IssueNotificationsTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private static int $seq = 0;

    protected function setUp(): void
    {
        // Un lunes lectivo.
        self::mockTime('2026-11-09 08:00:00');
        parent::setUp();
    }

    public function testANewIssueWarnsTheResponsiblesOfItsTypeAndItsAncestorsButNotTheReporter(): void
    {
        $w = $this->makeWorld();

        $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Sin luz</p>', []);

        self::assertEqualsCanonicalizing(['Ana Responsable', 'Luis Raíz'], $this->digestRecipients('issue_created'));
    }

    public function testWithoutResponsiblesTheCentreManagementIsWarned(): void
    {
        $w = $this->makeWorld();

        $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['limpieza'], null, null, '<p>Suelo sucio</p>', []);

        self::assertSame(['Admin Incidencias'], $this->digestRecipients('issue_created'));
    }

    public function testAStatusChangeWarnsTheReporterAndTheOtherResponsiblesButNotWhoMadeIt(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Sin luz</p>', []);
        $this->clearDigest();

        $this->manager()->transition($issue, $w['ana'], IssueStatus::Solved);

        self::assertEqualsCanonicalizing(['Rita Informante', 'Luis Raíz'], $this->digestRecipients('issue_status'));
    }

    public function testAnObservationIsEmailedWithItsTextToThoseWhoChoseImmediateDelivery(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Sin luz</p>', []);
        $this->setTeacherSetting($w['rita'], 'notifications.email_delivery', 'immediate');
        $this->clearDigest();

        $this->manager()->addObservation($issue, $w['ana'], 'Pedido el material, llega el jueves');

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'rita@example.org');
        self::assertEmailHtmlBodyContains($email, 'Pedido el material, llega el jueves');
    }

    public function testAPriorityChangeAndATypeChangeAreNotified(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Sin luz</p>', []);
        $this->clearDigest();

        $this->manager()->setPriority($issue, $w['ana'], $this->priority($w, 'Alta'));
        $this->manager()->changeType($issue, $w['ana'], $w['limpieza']);

        self::assertContains('Rita Informante', $this->digestRecipients('issue_priority'));
        self::assertContains('Rita Informante', $this->digestRecipients('issue_changed'));
    }

    public function testWhoDisabledANoticeDoesNotReceiveIt(): void
    {
        $w = $this->makeWorld();
        $this->setTeacherSetting($w['luis'], 'notifications.receive_issue_created', 'false');

        $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Sin luz</p>', []);

        self::assertSame(['Ana Responsable'], $this->digestRecipients('issue_created'));
    }

    public function testTheDailyReminderGroupsTheOverdueIssuesPerResponsible(): void
    {
        $w   = $this->makeWorld();
        $one = $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Una</p>', []);
        $two = $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Otra</p>', []);
        $one->setMaxDue(new \DateTimeImmutable('2026-11-02'));
        $two->setMaxDue(new \DateTimeImmutable('2026-11-05'));
        $this->flush();
        $this->clearDigest();

        $this->handler()(new IssueOverdueReminderMessage());

        $items = $this->em->getRepository(EmailDigestItem::class)->findBy(['eventKey' => 'issue_overdue']);
        self::assertCount(2, $items, 'una línea por responsable, no por incidencia');
        self::assertSame(2, $items[0]->getParams()['%count%']);
    }

    public function testNoReminderIsSentOnANonSchoolDayOrForIssuesWithinDeadline(): void
    {
        $w   = $this->makeWorld();
        $one = $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Una</p>', []);
        $one->setMaxDue(new \DateTimeImmutable('2026-11-12'));
        $this->flush();
        $this->clearDigest();

        $this->handler()(new IssueOverdueReminderMessage());
        self::assertCount(0, $this->em->getRepository(EmailDigestItem::class)->findBy(['eventKey' => 'issue_overdue']), 'todavía está en plazo');

        $one->setMaxDue(new \DateTimeImmutable('2026-11-02'));
        $this->flush();
        self::mockTime('2026-11-14 08:00:00'); // sábado
        $this->handler()(new IssueOverdueReminderMessage());

        self::assertCount(0, $this->em->getRepository(EmailDigestItem::class)->findBy(['eventKey' => 'issue_overdue']));
    }

    public function testClosedIssuesAreNotReminded(): void
    {
        $w   = $this->makeWorld();
        $one = $this->manager()->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Una</p>', []);
        $one->setMaxDue(new \DateTimeImmutable('2026-11-02'))->setStatus(IssueStatus::Solved);
        $this->flush();
        $this->clearDigest();

        $this->handler()(new IssueOverdueReminderMessage());

        self::assertCount(0, $this->em->getRepository(EmailDigestItem::class)->findBy(['eventKey' => 'issue_overdue']));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function manager(): IssueManager
    {
        return self::getContainer()->get(IssueManager::class);
    }

    private function handler(): IssueOverdueReminderHandler
    {
        return self::getContainer()->get(IssueOverdueReminderHandler::class);
    }

    /** @return list<string> los nombres de quienes tienen una línea de resumen con ese aviso */
    private function digestRecipients(string $eventKey): array
    {
        $names = [];
        foreach ($this->em->getRepository(EmailDigestItem::class)->findBy(['eventKey' => $eventKey]) as $item) {
            $names[] = $item->getRecipient()->getName()->full();
        }

        return $names;
    }

    private function clearDigest(): void
    {
        foreach ($this->em->getRepository(EmailDigestItem::class)->findAll() as $item) {
            $this->em->remove($item);
        }
        $this->flush();
    }

    /** @param array<string, mixed> $w */
    private function priority(array $w, string $name): IssuePriority
    {
        $found = $this->em->getRepository(IssuePriority::class)->findOneBy(['educationalCentre' => $w['centre'], 'name' => $name]);
        self::assertNotNull($found);

        return $found;
    }

    private function setTeacherSetting(Teacher $teacher, string $key, string $value): void
    {
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);
        self::assertNotNull($definition);
        $this->persist((new TeacherSettingValue())->setDefinition($definition)->setTeacher($teacher)->setValue($value));
        $this->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function makeWorld(): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Incidencias')))->setUsername("admin.not.$seq")->setAdmin(true)->setEmail("admin$seq@example.org");
        $ana    = (new Teacher(new PersonName('Ana', 'Responsable')))->setUsername("ana.not.$seq")->setEmail("ana$seq@example.org");
        $luis   = (new Teacher(new PersonName('Luis', 'Raíz')))->setUsername("luis.not.$seq")->setEmail("luis$seq@example.org");
        $rita   = (new Teacher(new PersonName('Rita', 'Informante')))->setUsername("rita.not.$seq")->setEmail('rita@example.org');
        $centre = (new EducationalCentre())->setCode("4700{$seq}55")->setName("IES Avisos $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        foreach ([$ana, $luis, $rita] as $teacher) {
            $year->addTeacher($teacher);
        }
        $mantenimiento = (new IssueType())->setEducationalCentre($centre)->setName('Mantenimiento')->addManager($luis);
        $electricidad  = (new IssueType())->setEducationalCentre($centre)->setName('Electricidad')->setParent($mantenimiento)->addManager($ana);
        $limpieza      = (new IssueType())->setEducationalCentre($centre)->setName('Limpieza');

        $this->persist($admin, $ana, $luis, $rita, $centre, $year, $mantenimiento, $electricidad, $limpieza);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($centre);
        $this->flush();

        return compact('admin', 'ana', 'luis', 'rita', 'centre', 'year', 'mantenimiento', 'electricidad', 'limpieza');
    }
}
