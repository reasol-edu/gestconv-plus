<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Activity;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AbsenceControllerTest extends ControllerTestCase
{
    private static int $scenarioCounter = 0;

    // ── index ────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToAnyTeacher(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias');

        self::assertResponseIsSuccessful();
    }

    public function testIndexWithoutCentreRedirectsToSelection(): void
    {
        $teacher = $this->makeTeacher('no.centre');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/ausencias');

        self::assertResponseRedirects();
        self::assertStringContainsString('/centro', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testIndexRedirectsUnauthenticated(): void
    {
        $this->client->request('GET', '/ausencias');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testIndexDeniesNonEnrolledNonAdminTeacher(): void
    {
        $suffix  = (string) ++self::$scenarioCounter;
        $teacher = $this->makeTeacher('teacher.' . uniqid('', false) . $suffix);
        $centre  = (new EducationalCentre())->setCode('4' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        // Deliberately not enrolled in the year's roster and not an admin.
        $this->persist($teacher, $centre, $year);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCentreTabAccessibleToAdminAndShowsListComponent(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $teacher->setAdmin(true);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias?tab=centre');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[data-model="dateFrom"]');
    }

    public function testIndexForAdminWithoutActiveYearShowsNoYearMessageInstead(): void
    {
        $suffix  = (string) ++self::$scenarioCounter;
        $admin   = $this->makeTeacher('admin.no.year.' . uniqid('', false) . $suffix);
        $admin->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('4' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Sin Curso')->setCity('Sevilla');
        // Deliberately no active academic year is set on the centre.
        $this->persist($admin, $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/ausencias');

        self::assertResponseIsSuccessful();
    }

    // ── new ──────────────────────────────────────────────────────────────────

    public function testNewGetRendersForm(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="start_date"]');
        self::assertSelectorExists('input[name="end_date"]');
    }

    public function testNewPostCreatesAbsenceAndRedirectsToShow(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/nuevo', [
            '_token'     => $token,
            'start_date' => $this->futureDateString(30),
            'end_date'   => $this->futureDateString(34),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $absences = $this->em->getRepository(Absence::class)->findAll();
        self::assertCount(1, $absences);
        self::assertSame($this->futureDateString(30), $absences[0]->getStartDate()->format('Y-m-d'));
        self::assertSame($teacher->getId()->toRfc4122(), $absences[0]->getTeacher()->getId()->toRfc4122());
    }

    public function testNewPostWithEndBeforeStartShowsError(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/nuevo', [
            '_token'     => $token,
            'start_date' => $this->futureDateString(34),
            'end_date'   => $this->futureDateString(30),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Absence::class)->findAll());
    }

    public function testAdminCanCreateAbsenceForAnotherTeacher(): void
    {
        [$admin, $centre, $year] = $this->makeScenario();
        $admin->setAdmin(true);
        $other = $this->makeTeacher('other.' . uniqid('', false));
        $year->addTeacher($other);
        $this->persist($admin, $other, $year);

        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/ausencias/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/nuevo', [
            '_token'     => $token,
            'start_date' => $this->futureDateString(30),
            'end_date'   => $this->futureDateString(34),
            'teacher_id' => $other->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $absences = $this->em->getRepository(Absence::class)->findAll();
        self::assertCount(1, $absences);
        self::assertSame($other->getId()->toRfc4122(), $absences[0]->getTeacher()->getId()->toRfc4122());
    }

    public function testAdminNewPostWithoutTeacherShowsError(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $admin->setAdmin(true);
        $this->flush();

        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/ausencias/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/nuevo', [
            '_token'     => $token,
            'start_date' => $this->futureDateString(30),
            'end_date'   => $this->futureDateString(34),
            'teacher_id' => '',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Absence::class)->findAll());
    }

    // ── show / edit / delete ────────────────────────────────────────────────

    public function testShowDisplaysAbsence(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makeAbsence($teacher, $year);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testShowDeniesUnrelatedTeacher(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makeAbsence($teacher, $year);
        $other   = $this->makeTeacher('unrelated');
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditPostUpdatesDates(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makeAbsence($teacher, $year);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/editar');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/' . $absence->getId()->toRfc4122() . '/editar', [
            '_token'     => $token,
            'start_date' => $this->futureDateString(31),
            'end_date'   => $this->futureDateString(35),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $updated = $this->em->getRepository(Absence::class)->find($absence->getId());
        self::assertSame($this->futureDateString(31), $updated->getStartDate()->format('Y-m-d'));
    }

    public function testDeletePostRemovesAbsence(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makeAbsence($teacher, $year);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/' . $absence->getId()->toRfc4122() . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/ausencias');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Absence::class)->findAll());
    }

    public function testOwnerCannotEditAbsenceWithPastEndDate(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makePastAbsence($teacher, $year);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCannotDeleteAbsenceWithPastEndDate(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makePastAbsence($teacher, $year);
        $this->loginAs($teacher, $centre);

        $this->client->request('POST', '/ausencias/' . $absence->getId()->toRfc4122() . '/eliminar', [
            '_token' => 'irrelevant-since-denied-before-csrf-check',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanStillViewAbsenceWithPastEndDate(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makePastAbsence($teacher, $year);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/editar"]');
    }

    public function testCentreAdminCanEditAbsenceWithPastEndDate(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makePastAbsence($teacher, $year);
        $admin   = $this->makeTeacher('centre.admin.' . uniqid('', false));
        $centre->addAdmin($admin);
        $this->persist($admin, $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
    }

    // ── activities ───────────────────────────────────────────────────────────

    public function testActivityNewGetRendersFormWithSubjectsAndTimeSlots(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makeAbsence($teacher, $year);
        $this->makeGroupTeacher($teacher, $year);
        $this->makeTimeSlot($year);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="subjects[]"]');
        self::assertSelectorExists('select[name="time_slot_id"] option[value]:not([value=""])[data-day-of-week]');
        self::assertSelectorExists('[data-controller="activity-time-slot"]');
    }

    public function testActivityNewGetPrefillsDateWithAbsenceStartDate(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence = $this->makeAbsence($teacher, $year);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva');

        self::assertResponseIsSuccessful();
        self::assertSame($this->futureDateString(30), $crawler->filter('input[name="date"]')->attr('value'));
    }

    public function testActivityNewPostCreatesActivityWithAttachment(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence     = $this->makeAbsence($teacher, $year);
        $groupTeacher = $this->makeGroupTeacher($teacher, $year);
        $timeSlot    = $this->makeTimeSlot($year, dayOfWeek: (int) $this->futureDate(31)->format('N') - 1);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmpFile, 'contenido de prueba');
        $upload = new UploadedFile($tmpFile, 'ejercicio.txt', 'text/plain', null, true);

        $this->client->request('POST', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva', [
            '_token'       => $token,
            'date'         => $this->futureDateString(31),
            'time_slot_id' => $timeSlot->getId()->toRfc4122(),
            'subjects'     => [$groupTeacher->getId()->toRfc4122()],
            'description'  => '<p>Ejercicios de repaso.</p>',
        ], ['attachments' => [$upload]]);

        self::assertResponseRedirects();
        $this->em->clear();
        $activities = $this->em->getRepository(Activity::class)->findAll();
        self::assertCount(1, $activities);
        self::assertCount(1, $activities[0]->getAttachments());
        self::assertSame('ejercicio.txt', $activities[0]->getAttachments()->first()->getFilename());

        @unlink($tmpFile);
    }

    public function testActivityNewPostWithoutSubjectsSucceeds(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence  = $this->makeAbsence($teacher, $year);
        $timeSlot = $this->makeTimeSlot($year, dayOfWeek: (int) $this->futureDate(31)->format('N') - 1);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva', [
            '_token'       => $token,
            'date'         => $this->futureDateString(31),
            'time_slot_id' => $timeSlot->getId()->toRfc4122(),
            'description'  => '<p>Sin materia asociada.</p>',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $activities = $this->em->getRepository(Activity::class)->findAll();
        self::assertCount(1, $activities);
        self::assertCount(0, $activities[0]->getSubjects());
    }

    public function testActivityNewShowsAbsenceOwnersSubjectsWhenAdminActsOnTheirBehalf(): void
    {
        [$admin, $centre, $year] = $this->makeScenario();
        $admin->setAdmin(true);
        $this->flush();

        $owner = $this->makeTeacher('activity.owner.' . uniqid('', false));
        $year->addTeacher($owner);
        $this->persist($owner, $year);

        $absence      = $this->makeAbsence($owner, $year);
        $groupTeacher = $this->makeGroupTeacher($owner, $year);
        $this->makeTimeSlot($year);

        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="subjects[]"][value="' . $groupTeacher->getId()->toRfc4122() . '"]');
    }

    public function testActivityNewPostWithDateOutsideRangeShowsError(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence     = $this->makeAbsence($teacher, $year);
        $groupTeacher = $this->makeGroupTeacher($teacher, $year);
        $timeSlot    = $this->makeTimeSlot($year);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva', [
            '_token'       => $token,
            'date'         => $this->futureDateString(60),
            'time_slot_id' => $timeSlot->getId()->toRfc4122(),
            'subjects'     => [$groupTeacher->getId()->toRfc4122()],
            'description'  => '<p>Fuera de rango.</p>',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Activity::class)->findAll());
    }

    public function testActivityNewPostWithTimeSlotDayMismatchShowsError(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence     = $this->makeAbsence($teacher, $year);
        $groupTeacher = $this->makeGroupTeacher($teacher, $year);
        // Force a slot whose day of week never matches the activity date's actual weekday.
        $actualDayOfWeek = (int) $this->futureDate(31)->format('N') - 1;
        $mismatchDay     = ($actualDayOfWeek + 1) % 7;
        $timeSlot        = $this->makeTimeSlot($year, dayOfWeek: $mismatchDay);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/nueva', [
            '_token'       => $token,
            'date'         => $this->futureDateString(31),
            'time_slot_id' => $timeSlot->getId()->toRfc4122(),
            'subjects'     => [$groupTeacher->getId()->toRfc4122()],
            'description'  => '<p>Día no coincide.</p>',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Activity::class)->findAll());
    }

    public function testActivityDeletePostRemovesActivity(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence      = $this->makeAbsence($teacher, $year);
        $groupTeacher = $this->makeGroupTeacher($teacher, $year);
        $timeSlot     = $this->makeTimeSlot($year, dayOfWeek: (int) $this->futureDate(31)->format('N') - 1);
        $activity     = $this->makeActivity($absence, $timeSlot, $groupTeacher, $this->futureDateString(31));
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/ausencias/' . $absence->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->last()->attr('value');

        $this->client->request(
            'POST',
            '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/' . $activity->getId()->toRfc4122() . '/eliminar',
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Activity::class)->findAll());
    }

    public function testAttachmentDownloadReturnsContent(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence      = $this->makeAbsence($teacher, $year);
        $groupTeacher = $this->makeGroupTeacher($teacher, $year);
        $timeSlot     = $this->makeTimeSlot($year, dayOfWeek: (int) $this->futureDate(31)->format('N') - 1);
        $activity     = $this->makeActivity($absence, $timeSlot, $groupTeacher, $this->futureDateString(31));

        $attachment = new \App\Entity\ActivityAttachment($activity, 'notas.txt', 'text/plain', 4, 'test');
        $activity->addAttachment($attachment);
        $this->persist($activity, $attachment);

        $this->loginAs($teacher, $centre);

        $this->client->request(
            'GET',
            '/ausencias/' . $absence->getId()->toRfc4122()
                . '/actividades/' . $activity->getId()->toRfc4122()
                . '/adjuntos/' . $attachment->getId()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->client->getResponse()->getContent());
    }

    public function testAttachmentDownloadAcceptsFilenamesWithNonAsciiCharacters(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence      = $this->makeAbsence($teacher, $year);
        $groupTeacher = $this->makeGroupTeacher($teacher, $year);
        $timeSlot     = $this->makeTimeSlot($year, dayOfWeek: (int) $this->futureDate(31)->format('N') - 1);
        $activity     = $this->makeActivity($absence, $timeSlot, $groupTeacher, $this->futureDateString(31));

        $attachment = new \App\Entity\ActivityAttachment($activity, 'Presentación del módulo.pdf', 'application/pdf', 4, 'test');
        $activity->addAttachment($attachment);
        $this->persist($activity, $attachment);

        $this->loginAs($teacher, $centre);

        $this->client->request(
            'GET',
            '/ausencias/' . $absence->getId()->toRfc4122()
                . '/actividades/' . $activity->getId()->toRfc4122()
                . '/adjuntos/' . $attachment->getId()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->client->getResponse()->getContent());
        self::assertStringContainsString('filename="Presentacion del modulo.pdf"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString("filename*=utf-8''Presentaci%C3%B3n", (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAttachmentDownloadAcceptsFilenamesWithCharactersThatCannotBeTransliterated(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $absence      = $this->makeAbsence($teacher, $year);
        $groupTeacher = $this->makeGroupTeacher($teacher, $year);
        $timeSlot     = $this->makeTimeSlot($year, dayOfWeek: (int) $this->futureDate(31)->format('N') - 1);
        $activity     = $this->makeActivity($absence, $timeSlot, $groupTeacher, $this->futureDateString(31));

        $attachment = new \App\Entity\ActivityAttachment($activity, '📎 tarea.pdf', 'application/pdf', 4, 'test');
        $activity->addAttachment($attachment);
        $this->persist($activity, $attachment);

        $this->loginAs($teacher, $centre);

        $this->client->request(
            'GET',
            '/ausencias/' . $absence->getId()->toRfc4122()
                . '/actividades/' . $activity->getId()->toRfc4122()
                . '/adjuntos/' . $attachment->getId()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->client->getResponse()->getContent());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear} */
    private function makeScenario(): array
    {
        $suffix  = (string) ++self::$scenarioCounter;
        $teacher = $this->makeTeacher('teacher.' . uniqid('', false) . $suffix);
        $centre  = (new EducationalCentre())->setCode('4' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);

        $this->persist($teacher, $centre, $year);

        return [$teacher, $centre, $year];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    /**
     * Dates are computed relative to "today" (instead of hard-coded) so the
     * fixture keeps producing a future, non-locked absence regardless of when
     * the test suite runs.
     */
    private function futureDate(int $daysFromToday): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $daysFromToday));
    }

    private function futureDateString(int $daysFromToday): string
    {
        return $this->futureDate($daysFromToday)->format('Y-m-d');
    }

    private function makeAbsence(Teacher $teacher, AcademicYear $year): Absence
    {
        $absence = (new Absence())
            ->setTeacher($teacher)
            ->setAcademicYear($year)
            ->setStartDate($this->futureDate(30))
            ->setEndDate($this->futureDate(34));
        $this->persist($absence);

        return $absence;
    }

    private function makePastAbsence(Teacher $teacher, AcademicYear $year): Absence
    {
        $absence = (new Absence())
            ->setTeacher($teacher)
            ->setAcademicYear($year)
            ->setStartDate(new \DateTimeImmutable('2020-02-02'))
            ->setEndDate(new \DateTimeImmutable('2020-02-06'));
        $this->persist($absence);

        return $absence;
    }

    private function makeGroupTeacher(Teacher $teacher, AcademicYear $year): GroupTeacher
    {
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);
        $this->persist($course, $group);

        $groupTeacher = new GroupTeacher($group, $teacher, 'Programación');
        $this->persist($groupTeacher);

        return $groupTeacher;
    }

    private function makeTimeSlot(AcademicYear $year, int $dayOfWeek = 1): TimeSlot
    {
        $timeSlot = (new TimeSlot())
            ->setName('Tramo 1')
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable('08:00'))
            ->setEndTime(new \DateTimeImmutable('09:00'))
            ->setAcademicYear($year);
        $this->persist($timeSlot);

        return $timeSlot;
    }

    private function makeActivity(Absence $absence, TimeSlot $timeSlot, GroupTeacher $subject, string $date): Activity
    {
        $activity = (new Activity())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable($date))
            ->setTimeSlot($timeSlot)
            ->setDescription('<p>Actividad de prueba.</p>');
        $activity->addSubject($subject);
        $this->persist($activity);

        return $activity;
    }
}
