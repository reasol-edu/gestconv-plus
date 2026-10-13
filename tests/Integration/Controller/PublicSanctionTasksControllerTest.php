<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\SanctionTaskAttachment;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class PublicSanctionTasksControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private const CODE = 'ABCDE23456';

    protected function setUp(): void
    {
        self::mockTime('2026-03-10 10:00:00');
        parent::setUp();
    }

    // ── acceso público ────────────────────────────────────────────────────────

    public function testEntryPageShowsCodeForm(): void
    {
        $this->client->request('GET', '/t');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="code"]');
    }

    public function testEntryRedirectsToCanonicalCodeIgnoringCaseAndSeparators(): void
    {
        $this->client->request('POST', '/t', ['code' => ' abcde 23456 ']);

        self::assertResponseRedirects('/t/ABCDE-23456');
    }

    public function testEntryRejectsMalformedCode(): void
    {
        $this->client->request('POST', '/t', ['code' => 'abc']);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('body', 'no es válido');
    }

    public function testViewShowsStudentGroupDatesAndTasksWithoutLogin(): void
    {
        $this->makeSanction('2026-03-02', '2026-03-12');

        $this->client->request('GET', '/t/ABCDE-23456');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'García, Ana');
        self::assertSelectorTextContains('body', '1ºA');
        self::assertSelectorTextContains('body', '02/03/2026');
        self::assertSelectorTextContains('body', '12/03/2026');
        self::assertSelectorTextContains('body', 'Matemáticas');
        self::assertSelectorTextContains('body', 'Hacer los ejercicios 1 a 5');
        self::assertSelectorTextContains('body', 'ejercicios.pdf');
        self::assertSelectorExists('a[href$="/tareas.zip"]');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testCodeWorksWithoutHyphensAndInLowercase(): void
    {
        $this->makeSanction('2026-03-02', '2026-03-12');

        $this->client->request('GET', '/t/abcde23456');

        self::assertResponseIsSuccessful();
    }

    public function testLastDayOfSanctionIsStillAccessible(): void
    {
        $this->makeSanction('2026-03-02', '2026-03-10');

        $this->client->request('GET', '/t/ABCDE-23456');

        self::assertResponseIsSuccessful();
    }

    public function testExpiredLinkShowsNothing(): void
    {
        $this->makeSanction('2026-03-02', '2026-03-09');

        $this->client->request('GET', '/t/ABCDE-23456');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextNotContains('body', 'García');
    }

    public function testUnknownCodeIsNotFound(): void
    {
        $this->makeSanction('2026-03-02', '2026-03-12');

        $this->client->request('GET', '/t/ZZZZZ-ZZZZZ');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAttachmentDownloadAndZip(): void
    {
        $this->makeSanction('2026-03-02', '2026-03-12');
        $attachment = $this->em->getRepository(SanctionTaskAttachment::class)->findOneBy([]);
        self::assertNotNull($attachment);

        $this->client->request('GET', '/t/ABCDE-23456/' . $attachment->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSame('contenido', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/t/ABCDE-23456/tareas.zip');
        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testAttachmentOfAnotherSanctionIsNotServed(): void
    {
        $this->makeSanction('2026-03-02', '2026-03-12');
        $attachment = $this->em->getRepository(SanctionTaskAttachment::class)->findOneBy([]);
        self::assertNotNull($attachment);
        // Segunda sanción con otro código
        $other = $this->em->getRepository(Sanction::class)->findOneBy([]);
        self::assertNotNull($other);
        $second = (new Sanction())
            ->setAcademicYear($other->getAcademicYear())
            ->setStudent($other->getStudent())
            ->setGroup($other->getGroup())
            ->setRegisteredBy($other->getRegisteredBy())
            ->setDetails('x')
            ->setNoMeasureApplied(false)
            ->setEffectiveTo(new \DateTimeImmutable('2026-03-12'))
            ->setShareCode('FGHIJ77777');
        $this->persist($second);

        $this->client->request('GET', '/t/FGHIJ-77777/' . $attachment->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    // ── gestión del enlace ────────────────────────────────────────────────────

    public function testTutorCanGenerateRegenerateAndDeleteLink(): void
    {
        [$sanction, $tutor, $centre] = $this->makeSanction('2026-03-02', '2026-03-12', code: null);
        $id = $sanction->getId()->toRfc4122();
        $this->loginAs($tutor, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $id);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Generar enlace')->form());
        self::assertResponseRedirects('/sanciones/' . $id);

        $this->em->clear();
        $first = $this->em->getRepository(Sanction::class)->find($sanction->getId())?->getShareCode();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{10}$/', (string) $first);

        $crawler = $this->client->request('GET', '/sanciones/' . $id);
        $this->client->submit($crawler->selectButton('Regenerar')->form());
        $this->em->clear();
        $second = $this->em->getRepository(Sanction::class)->find($sanction->getId())?->getShareCode();
        self::assertNotNull($second);
        self::assertNotSame($first, $second);

        $crawler = $this->client->request('GET', '/sanciones/' . $id);
        $this->client->submit($crawler->selectButton('Eliminar')->form());
        $this->em->clear();
        self::assertNull($this->em->getRepository(Sanction::class)->find($sanction->getId())?->getShareCode());
    }

    public function testOtherTeacherCannotManageLink(): void
    {
        [$sanction, , $centre] = $this->makeSanction('2026-03-02', '2026-03-12', code: null);
        $other = (new Teacher(new PersonName('Otro', 'Docente')))->setUsername('otro.docente');
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('POST', '/sanciones/' . $sanction->getId()->toRfc4122() . '/enlace', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPdfNeverCreatesTheLink(): void
    {
        [$sanction, , $centre, $admin] = $this->makeSanction('2026-03-02', '2026-03-12', code: null);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/pdf');

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Sanction::class)->find($sanction->getId())?->getShareCode());
    }

    public function testPdfWithExistingLinkRenders(): void
    {
        [$sanction, , $centre, $admin] = $this->makeSanction('2026-03-02', '2026-03-12');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/pdf');

        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{0: Sanction, 1: Teacher, 2: EducationalCentre, 3: Teacher}
     */
    private function makeSanction(string $from, string $to, ?string $code = self::CODE): array
    {
        $admin  = (new Teacher(new PersonName('Admin', 'Centro')))->setUsername('cadmin.' . uniqid('', false));
        $tutor  = (new Teacher(new PersonName('Tutor', 'Grupo')))->setUsername('tutor.' . uniqid('', false));
        $math   = (new Teacher(new PersonName('Profe', 'Mates')))->setUsername('mates.' . uniqid('', false));
        $centre = (new EducationalCentre())->setCode('41000001')->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));

        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        $group->addStudent($student);
        $this->persist($admin, $tutor, $math, $centre, $year, $course, $group, $student);
        $group->addTutor($tutor);
        $group->addTeacher($math, 'Matemáticas');
        $this->flush();

        $sanction = (new Sanction())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($admin)
            ->setDetails('Detalles.')
            ->setNoMeasureApplied(false)
            ->setEffectiveFrom(new \DateTimeImmutable($from))
            ->setEffectiveTo(new \DateTimeImmutable($to))
            ->setShareCode($code);
        $this->persist($sanction);

        $task = new SanctionTask($sanction, $group->getTeacherAssignments()->first());
        $task->setDescription('<p>Hacer los ejercicios 1 a 5</p>');
        $this->persist($task, new SanctionTaskAttachment($task, 'ejercicios.pdf', 'application/pdf', 9, 'contenido'));
        $this->flush();

        return [$sanction, $tutor, $centre, $admin];
    }
}
