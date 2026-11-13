<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\PushSubscription;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Message\NotifyLongExitsMessage;
use App\MessageHandler\NotifyLongExitsHandler;
use App\Repository\PushSubscriptionRepository;
use App\Repository\StudentExitRepository;
use App\Tests\Integration\RepositoryTestCase;
use App\Tests\Support\RecordingPushDispatcher;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class NotifyLongExitsHandlerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private NotifyLongExitsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-14 10:00:00');
        RecordingPushDispatcher::reset();
        RecordingPushDispatcher::$configured = true;

        /** @var NotifyLongExitsHandler $handler */
        $handler       = self::getContainer()->get(NotifyLongExitsHandler::class);
        $this->handler = $handler;
    }

    protected function tearDown(): void
    {
        RecordingPushDispatcher::reset();
        parent::tearDown();
    }

    public function testNotifiesTheAuthorizingTeacherOnceAfterThirtyMinutes(): void
    {
        $w    = $this->world('a');
        $long = $this->makeExit($w, '2026-10-14 09:20:00'); // 40 min
        $this->makeExit($w, '2026-10-14 09:50:00');          // 10 min: aún no
        $this->em->flush();
        $this->subscribe($w['teacher'], 'https://fcm.googleapis.com/fcm/send/abc');

        ($this->handler)(new NotifyLongExitsMessage());

        self::assertCount(1, RecordingPushDispatcher::$sent);
        $sent = RecordingPushDispatcher::$sent[0];
        self::assertSame(['https://fcm.googleapis.com/fcm/send/abc'], $sent['endpoints']);
        self::assertStringContainsString('Ana García', $sent['payload']['body']);
        self::assertSame('/salidas', $sent['payload']['url']);

        $this->em->clear();
        self::assertNotNull($this->reload($long->getId()->toRfc4122())->getLongNoticeSentAt());

        // Una segunda pasada no repite el aviso.
        ($this->handler)(new NotifyLongExitsMessage());
        self::assertCount(1, RecordingPushDispatcher::$sent);
    }

    public function testNothingIsSentOrMarkedWithoutVapidKeys(): void
    {
        RecordingPushDispatcher::$configured = false;
        $w    = $this->world('b');
        $long = $this->makeExit($w, '2026-10-14 09:00:00');
        $this->em->flush();
        $this->subscribe($w['teacher'], 'https://fcm.googleapis.com/fcm/send/abc');
        $id = $long->getId()->toRfc4122();

        ($this->handler)(new NotifyLongExitsMessage());

        self::assertSame([], RecordingPushDispatcher::$sent);
        $this->em->clear();
        self::assertNull($this->reload($id)->getLongNoticeSentAt(), 'Cuando se configuren las claves, el aviso aún podrá enviarse.');
    }

    public function testIgnoresReturnedClosedAndPreviousDayExits(): void
    {
        $w        = $this->world('c');
        $returned = $this->makeExit($w, '2026-10-14 09:00:00');
        $returned->markReturned($w['teacher'], new \DateTimeImmutable('2026-10-14 09:10:00'));
        $closed = $this->makeExit($w, '2026-10-14 08:00:00');
        $closed->markAutoClosed(new \DateTimeImmutable('2026-10-14 08:30:00'));
        $this->makeExit($w, '2026-10-13 12:00:00'); // de ayer: lo cierra la tarea nocturna, no se avisa
        $this->em->flush();
        $this->subscribe($w['teacher'], 'https://fcm.googleapis.com/fcm/send/abc');

        ($this->handler)(new NotifyLongExitsMessage());

        self::assertSame([], RecordingPushDispatcher::$sent);
    }

    public function testTeachersWithoutSubscriptionsGetNothingButTheExitIsStillMarked(): void
    {
        $w = $this->world('d');
        $this->makeExit($w, '2026-10-14 09:00:00');
        $this->em->flush();

        ($this->handler)(new NotifyLongExitsMessage());

        self::assertSame([], RecordingPushDispatcher::$sent);
    }

    public function testSkipsCentresWithTheExitsSectionDisabled(): void
    {
        $w          = $this->world('e');
        $this->makeExit($w, '2026-10-14 09:00:00');
        $definition = (new SettingDefinition())->setKey('features.exits')->setType(SettingType::Boolean)
            ->setCategory('settings.category.features')->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new CentreSettingValue())->setDefinition($definition)->setCentre($w['centre'])->setValue('false'));
        $this->subscribe($w['teacher'], 'https://fcm.googleapis.com/fcm/send/abc');

        ($this->handler)(new NotifyLongExitsMessage());

        self::assertSame([], RecordingPushDispatcher::$sent);
    }

    public function testSubscriptionsTheServiceReportsAsExpiredAreRemoved(): void
    {
        $w = $this->world('f');
        $this->makeExit($w, '2026-10-14 09:00:00');
        $this->em->flush();
        $this->subscribe($w['teacher'], 'https://fcm.googleapis.com/fcm/send/vieja');
        $this->subscribe($w['teacher'], 'https://fcm.googleapis.com/fcm/send/vigente');
        RecordingPushDispatcher::$expire = ['https://fcm.googleapis.com/fcm/send/vieja'];

        ($this->handler)(new NotifyLongExitsMessage());

        /** @var PushSubscriptionRepository $repo */
        $repo = self::getContainer()->get(PushSubscriptionRepository::class);
        $this->em->clear();
        $left = array_map(static fn (PushSubscription $s): string => $s->getEndpoint(), $repo->findByTeacher($this->em->getRepository(Teacher::class)->find($w['teacher']->getId())));
        self::assertSame(['https://fcm.googleapis.com/fcm/send/vigente'], $left);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function subscribe(Teacher $teacher, string $endpoint): void
    {
        $this->persist(new PushSubscription($teacher, $endpoint, 'clave', 'auth'));
    }

    private function reload(string $id): StudentExit
    {
        /** @var StudentExitRepository $repo */
        $repo   = self::getContainer()->get(StudentExitRepository::class);
        $result = $repo->findById($id);
        self::assertNotNull($result);

        return $result;
    }

    /** @param array<string, mixed> $w */
    private function makeExit(array $w, string $at): StudentExit
    {
        $exit = (new StudentExit())
            ->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setReason($w['reason'])
            ->setAuthorizedBy($w['teacher'])->setExitedAt(new \DateTimeImmutable($at));
        $this->em->persist($exit);

        return $exit;
    }

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, reason: ExitReason} */
    private function world(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('42' . substr(md5($suffix . 'nle'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.nle.' . $suffix);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-nle-' . $suffix);
        $student->addGroup($group);
        $reason = (new ExitReason())->setEducationalCentre($centre)->setName('Servicio')->setPosition(0);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $teacher, $student, $reason);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'reason');
    }
}
