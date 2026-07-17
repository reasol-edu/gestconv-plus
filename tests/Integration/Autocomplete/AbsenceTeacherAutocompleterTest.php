<?php

declare(strict_types=1);

namespace App\Tests\Integration\Autocomplete;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class AbsenceTeacherAutocompleterTest extends ControllerTestCase
{
    private static int $scenarioCounter = 0;

    public function testOnlyReturnsTeachersWithAbsencesInTheGivenYear(): void
    {
        [$admin, $centre, $year] = $this->makeScenario();
        $admin->setAdmin(true);

        $withAbsence    = (new Teacher(new PersonName('Alicia', 'Ausente')))->setUsername('alicia.' . uniqid('', false));
        $withoutAbsence = (new Teacher(new PersonName('Bruno', 'Presente')))->setUsername('bruno.' . uniqid('', false));
        $year->addTeacher($withAbsence);
        $year->addTeacher($withoutAbsence);
        $this->persist($admin, $withAbsence, $withoutAbsence);

        $absence = (new Absence())
            ->setTeacher($withAbsence)
            ->setAcademicYear($year)
            ->setStartDate(new \DateTimeImmutable('+30 days'))
            ->setEndDate(new \DateTimeImmutable('+34 days'));
        $this->persist($absence);

        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/autocomplete/absence_teacher', [
            'query'          => 'e',
            'academicYearId' => $year->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $labels  = array_column($payload['results'], 'text');

        self::assertContains('Ausente, Alicia', $labels);
        self::assertNotContains('Presente, Bruno', $labels);
    }

    public function testDeniedToNonAdminTeacher(): void
    {
        [$teacher, $centre, $year] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/autocomplete/absence_teacher', [
            'query'          => 'Test',
            'academicYearId' => $year->getId()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

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
        return (new Teacher(new PersonName('Test', ucfirst(str_replace('.', ' ', $username)))))->setUsername($username . '.' . uniqid('', false));
    }
}
