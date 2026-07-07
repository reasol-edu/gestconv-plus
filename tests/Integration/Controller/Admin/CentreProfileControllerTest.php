<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class CentreProfileControllerTest extends ControllerTestCase
{
    // ── index: acceso ────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/perfiles');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsAccessibleToGlobalAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $globalAdmin = $this->makeTeacher('global.admin.profiles', admin: true);
        $this->persist($globalAdmin);
        $this->loginAs($globalAdmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/perfiles');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.profiles');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/perfiles');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexShowsNoActiveYearMessageWhenCentreHasNoActiveYear(): void
    {
        $cadmin = $this->makeTeacher('cadmin.noyear', admin: true);
        $centre = (new EducationalCentre())->setCode('41' . substr(uniqid('', false), 0, 6))->setName('IES Sin Año')->setCity('Sevilla');
        $centre->addAdmin($cadmin);
        $this->persist($cadmin, $centre);

        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/perfiles');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form');
    }

    // ── index: contenido ─────────────────────────────────────────────────────

    public function testIndexPreselectsExistingCommitteeMembersAndCounselors(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $member    = $this->makeTeacher('committee.existing');
        $counselor = $this->makeTeacher('counselor.existing');
        $this->persist($member, $counselor);
        $centre->addCommitteeMember($member);
        $centre->addCounselor($counselor);
        $this->flush();

        $this->loginAs($cadmin);

        $crawler = $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/perfiles');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="committee_members[]"] option[value="' . $member->getId()->toRfc4122() . '"][selected]');
        self::assertSelectorExists('select[name="counselors[]"] option[value="' . $counselor->getId()->toRfc4122() . '"][selected]');
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSavePostAssignsCommitteeAndCounselorProfiles(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $member    = $this->makeTeacher('committee.new');
        $counselor = $this->makeTeacher('counselor.new');
        $this->persist($member, $counselor);

        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/perfiles');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/perfiles', [
            '_token'           => $token,
            'committee_members' => [$member->getId()->toRfc4122()],
            'counselors'         => [$counselor->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/perfiles');

        $this->em->clear();
        $updated          = $this->em->find(EducationalCentre::class, $centre->getId());
        $refetchedMember  = $this->em->find(Teacher::class, $member->getId());
        $refetchedCounselor = $this->em->find(Teacher::class, $counselor->getId());
        self::assertNotNull($updated);
        self::assertTrue($updated->getCommitteeMembers()->contains($refetchedMember));
        self::assertTrue($updated->getCounselors()->contains($refetchedCounselor));
    }

    public function testSavePostRemovesTeachersNotResubmitted(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $member = $this->makeTeacher('committee.toremove');
        $this->persist($member);
        $centre->addCommitteeMember($member);
        $this->flush();

        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/perfiles');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/perfiles', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/perfiles');

        $this->em->clear();
        $updated         = $this->em->find(EducationalCentre::class, $centre->getId());
        $refetchedMember = $this->em->find(Teacher::class, $member->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->getCommitteeMembers()->contains($refetchedMember));
    }

    public function testSaveWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . '/perfiles', [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('41' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    private function makeTeacher(string $username, bool $admin = false): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername($username)
            ->setAdmin($admin);
    }
}
