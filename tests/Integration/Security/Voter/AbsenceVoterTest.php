<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\Voter;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Security\Voter\AbsenceVoter;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class AbsenceVoterTest extends RepositoryTestCase
{
    private AbsenceVoter $voter;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var AbsenceVoter $voter */
        $voter       = self::getContainer()->get(AbsenceVoter::class);
        $this->voter = $voter;
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        [$absence] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($absence->getTeacher()), $absence, ['unknown'])
        );
    }

    public function testAbstainsWhenSubjectIsNotAbsence(): void
    {
        $teacher = $this->makeTeacher('abstain.subject');
        $this->persist($teacher);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($teacher), new \stdClass(), [AbsenceVoter::VIEW])
        );
    }

    public function testGlobalAdminIsGrantedEverything(): void
    {
        [$absence] = $this->makeScenario();
        $admin     = $this->makeTeacher('global.admin', admin: true);
        $this->persist($admin);

        foreach ([AbsenceVoter::VIEW, AbsenceVoter::EDIT, AbsenceVoter::DELETE] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->voter->vote($this->token($admin), $absence, [$attribute])
            );
        }
    }

    public function testOwnerIsGrantedEverything(): void
    {
        [$absence] = $this->makeScenario();

        foreach ([AbsenceVoter::VIEW, AbsenceVoter::EDIT, AbsenceVoter::DELETE] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->voter->vote($this->token($absence->getTeacher()), $absence, [$attribute])
            );
        }
    }

    public function testCentreAdminIsGrantedViewOnly(): void
    {
        [$absence, $centre] = $this->makeScenario();
        $cadmin              = $this->makeTeacher('centre.admin');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $absence, [AbsenceVoter::VIEW])
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($cadmin), $absence, [AbsenceVoter::EDIT])
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($cadmin), $absence, [AbsenceVoter::DELETE])
        );
    }

    public function testCentreAdminOfDifferentCentreIsDenied(): void
    {
        [$absence] = $this->makeScenario();

        $otherCentre = (new EducationalCentre())->setCode('41900098')->setName('Other')->setCity('Sevilla');
        $cadmin      = $this->makeTeacher('other.centre.admin');
        $this->persist($otherCentre, $cadmin);
        $otherCentre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($cadmin), $absence, [AbsenceVoter::VIEW])
        );
    }

    public function testUnrelatedTeacherIsDenied(): void
    {
        [$absence] = $this->makeScenario();
        $other     = $this->makeTeacher('unrelated');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $absence, [AbsenceVoter::VIEW])
        );
    }

    public function testAnonymousIsDenied(): void
    {
        [$absence] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonymousToken(), $absence, [AbsenceVoter::VIEW])
        );
    }

    /** @return array{0: Absence, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $centre  = (new EducationalCentre())->setCode('41000' . substr(md5(uniqid('', true)), 0, 3))->setName('IES')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $teacher = $this->makeTeacher('owner.' . uniqid('', true));
        $this->persist($centre, $year, $teacher);

        $absence = (new Absence())
            ->setTeacher($teacher)
            ->setAcademicYear($year)
            ->setStartDate(new \DateTimeImmutable('2026-01-12'))
            ->setEndDate(new \DateTimeImmutable('2026-01-14'));
        $this->persist($absence);

        return [$absence, $centre];
    }

    private function makeTeacher(string $username, bool $admin = false): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername($username)
            ->setAdmin($admin);
    }

    private function token(Teacher $teacher): TokenInterface
    {
        $stub = $this->createStub(TokenInterface::class);
        $stub->method('getUser')->willReturn($teacher);

        return $stub;
    }

    private function anonymousToken(): TokenInterface
    {
        $stub = $this->createStub(TokenInterface::class);
        $stub->method('getUser')->willReturn(null);

        return $stub;
    }
}
