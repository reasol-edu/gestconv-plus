<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\OfferExporter;
use App\Service\OfferImporter;
use App\Service\OfferImportOptions;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Exportación e importación de la oferta formativa en JSON, con vista previa y selección. */
class OfferImportExportTest extends ControllerTestCase
{
    private static int $seq = 0;

    // ── exportación ───────────────────────────────────────────────────────────

    public function testExportWritesTeachersAsUsernameAndName(): void
    {
        $w = $this->makeWorld();

        $data = $this->exporter()->export($w['centre'], $w['sourceYear']);

        self::assertSame(OfferExporter::FORMAT, $data['format']);
        self::assertSame('2024-2025', $data['academic_year']);
        self::assertCount(1, $data['courses']);
        $group = $data['courses'][0]['groups'][0];
        self::assertSame('1ºA', $group['name']);
        self::assertSame('#d97706', $group['color']);
        self::assertSame([['username' => $w['ana']->getUsername(), 'name' => 'Ana Tutora']], $group['tutors']);
        self::assertSame(
            [['subject' => 'Matemáticas', 'teacher' => ['username' => $w['luis']->getUsername(), 'name' => 'Luis Docente']]],
            $group['subjects'],
        );
    }

    public function testExportRouteDownloadsTheViewedYearAsJson(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $this->viewPastYear($w['sourceYear']);

        $this->client->request('GET', $this->url($w['centre']) . '/export');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('2024-2025', $data['academic_year']);
        self::assertSame('1ºA', $data['courses'][0]['groups'][0]['name']);
    }

    // ── importación: servicio ─────────────────────────────────────────────────

    public function testRoundTripIntoAnotherYearRecreatesTheOfferWithTeachersResolvedByUsername(): void
    {
        $w = $this->makeWorld();
        $file = $this->importer()->parse($this->exporter()->export($w['centre'], $w['sourceYear']));
        self::assertNotNull($file);

        $result = $this->importer()->import($file, $w['targetYear'], [0], [0 => [0]], new OfferImportOptions());

        self::assertSame(1, $result->coursesCreated);
        self::assertSame(1, $result->groupsCreated);
        self::assertSame(1, $result->subjectsAdded);
        self::assertSame([], $result->unresolved);
        $group = $this->targetGroup($w['targetYear'], '1ºA');
        self::assertSame('#d97706', $group->getColor());
        self::assertSame('Segundo de prueba', $group->getDetails());
        self::assertSame([$w['ana']->getUsername()], array_map(static fn (Teacher $t): string => $t->getUsername(), $group->getTutors()->toArray()));
        self::assertCount(1, $group->getTeacherAssignments());
        self::assertSame('Matemáticas', $group->getTeacherAssignments()->first()->getSubject());
    }

    public function testUsernamesAreMatchedIgnoringCase(): void
    {
        $w = $this->makeWorld();
        $data = $this->exporter()->export($w['centre'], $w['sourceYear']);
        $data['courses'][0]['groups'][0]['tutors'][0]['username'] = strtoupper($w['ana']->getUsername());
        $file = $this->importer()->parse($data);

        $this->importer()->import($file, $w['targetYear'], [0], [0 => [0]], new OfferImportOptions());

        self::assertCount(1, $this->targetGroup($w['targetYear'], '1ºA')->getTutors());
    }

    public function testUnknownTeachersAreReportedAndSkippedNotFatal(): void
    {
        $w = $this->makeWorld();
        $data = $this->exporter()->export($w['centre'], $w['sourceYear']);
        $data['courses'][0]['groups'][0]['tutors'][] = ['username' => 'no.existe', 'name' => 'Fantasma Nadie'];
        $data['courses'][0]['groups'][0]['subjects'][] = ['subject' => 'Física', 'teacher' => ['username' => 'tampoco', 'name' => null]];
        $file = $this->importer()->parse($data);

        $preview = $this->importer()->preview($file, $w['targetYear']);
        $result  = $this->importer()->import($file, $w['targetYear'], [0], [0 => [0]], new OfferImportOptions());

        self::assertSame(['no.existe' => 'Fantasma Nadie', 'tampoco' => null], $preview['unresolved']);
        self::assertSame(['no.existe (Fantasma Nadie)', 'tampoco'], $result->unresolved);
        $group = $this->targetGroup($w['targetYear'], '1ºA');
        self::assertCount(1, $group->getTutors(), 'solo el docente que existe');
        self::assertCount(1, $group->getTeacherAssignments(), 'la materia con un docente desconocido se omite');
    }

    public function testEachOptionOffLeavesThatInformationOutOfNewGroups(): void
    {
        $w = $this->makeWorld();
        $file = $this->importer()->parse($this->exporter()->export($w['centre'], $w['sourceYear']));

        $this->importer()->import($file, $w['targetYear'], [0], [0 => [0]], new OfferImportOptions(tutors: false, subjects: false, color: false));

        $group = $this->targetGroup($w['targetYear'], '1ºA');
        self::assertNull($group->getColor());
        self::assertCount(0, $group->getTutors());
        self::assertCount(0, $group->getTeacherAssignments());
        self::assertSame('Segundo de prueba', $group->getDetails(), 'los datos básicos se importan siempre');
    }

    public function testIgnoreLeavesMatchedElementsUntouchedAndOnlyCreatesWhatIsMissing(): void
    {
        $w = $this->makeWorld();
        $existing = $this->makeTargetGroup($w, '1ºA', '#111111');
        $data = $this->exporter()->export($w['centre'], $w['sourceYear']);
        $data['courses'][0]['groups'][] = ['name' => '1ºB', 'details' => null, 'color' => null, 'tutors' => [], 'subjects' => []];
        $file = $this->importer()->parse($data);

        $result = $this->importer()->import($file, $w['targetYear'], [0], [0 => [0, 1]], new OfferImportOptions(replace: false));

        self::assertSame(1, $result->coursesIgnored);
        self::assertSame(1, $result->groupsIgnored);
        self::assertSame(1, $result->groupsCreated);
        $this->em->clear();
        $found = $this->em->getRepository(Group::class)->find($existing->getId());
        self::assertSame('#111111', $found->getColor(), 'el grupo existente no se toca');
        self::assertCount(0, $found->getTutors());
        self::assertNotNull($this->targetGroup($w['targetYear'], '1ºB'));
    }

    public function testReplaceOverwritesMatchedGroupsButNeverDeletesOthers(): void
    {
        $w = $this->makeWorld();
        $existing = $this->makeTargetGroup($w, '1ºA', '#111111');
        $other    = $this->makeTargetGroup($w, '9ºZ', null);
        $file = $this->importer()->parse($this->exporter()->export($w['centre'], $w['sourceYear']));

        $result = $this->importer()->import($file, $w['targetYear'], [0], [0 => [0]], new OfferImportOptions(replace: true));

        self::assertSame(1, $result->groupsUpdated);
        $this->em->clear();
        $found = $this->em->getRepository(Group::class)->find($existing->getId());
        self::assertSame('#d97706', $found->getColor());
        self::assertCount(1, $found->getTutors());
        self::assertNotNull($this->em->getRepository(Group::class)->find($other->getId()), 'un grupo que no está en el fichero no se borra');
    }

    public function testReplaceKeepsAnAssignmentThatHasSanctionTasks(): void
    {
        $w = $this->makeWorld();
        $existing = $this->makeTargetGroup($w, '1ºA', null);
        $existing->addTeacher($w['ana'], 'Dibujo');
        $this->flush();
        $stale = $existing->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $stale);
        $student  =(new Student(new PersonName('Alu', 'Mno')))->setStudentId('NIE-OFFER-' . ++self::$seq);
        $this->persist($student);
        $sanction = (new Sanction())->setAcademicYear($w['targetYear'])->setStudent($student)->setGroup($existing)
            ->setRegisteredBy($w['admin'])->setDetails('x')->setNoMeasureApplied(false);
        $this->persist($sanction);
        $this->persist(new SanctionTask($sanction, $stale));
        $this->flush();
        $file = $this->importer()->parse($this->exporter()->export($w['centre'], $w['sourceYear']));

        $result = $this->importer()->import($file, $w['targetYear'], [0], [0 => [0]], new OfferImportOptions(replace: true));

        self::assertSame(1, $result->subjectsKept, 'la materia con tareas de sanción se conserva');
        self::assertSame(0, $result->subjectsRemoved);
        $this->em->clear();
        $subjects = array_map(
            static fn (GroupTeacher $gt): string => $gt->getSubject(),
            $this->em->getRepository(Group::class)->find($existing->getId())->getTeacherAssignments()->toArray(),
        );
        sort($subjects);
        self::assertSame(['Dibujo', 'Matemáticas'], $subjects);
    }

    public function testPartialSelectionImportsOnlyTheChosenGroupsAndTheirCourse(): void
    {
        $w = $this->makeWorld();
        $data = $this->exporter()->export($w['centre'], $w['sourceYear']);
        $data['courses'][0]['groups'][] = ['name' => '1ºB', 'details' => null, 'color' => null, 'tutors' => [], 'subjects' => []];
        $data['courses'][] = ['name' => '2º ESO', 'details' => null, 'groups' => [['name' => '2ºA', 'details' => null, 'color' => null, 'tutors' => [], 'subjects' => []]]];
        $file = $this->importer()->parse($data);

        // Solo el grupo 1ºB: se importa también su curso, pero no 1ºA ni el curso «2º ESO».
        $result = $this->importer()->import($file, $w['targetYear'], [], [0 => [1]], new OfferImportOptions());

        self::assertSame(1, $result->coursesCreated);
        self::assertSame(1, $result->groupsCreated);
        self::assertNotNull($this->targetGroup($w['targetYear'], '1ºB'));
        self::assertNull($this->targetGroup($w['targetYear'], '1ºA'));
        self::assertNull($this->targetGroup($w['targetYear'], '2ºA'));
    }

    public function testParseRejectsFilesThatAreNotAnOfferAndNewerVersions(): void
    {
        self::assertNull($this->importer()->parse(['categories' => []]), 'el catálogo de recursos no es una oferta');
        self::assertNull($this->importer()->parse(['format' => OfferExporter::FORMAT, 'version' => 99, 'courses' => []]));
        self::assertNull($this->importer()->parse('texto'));
        self::assertSame([], $this->importer()->parse(['format' => OfferExporter::FORMAT, 'courses' => []])['courses']);
    }

    // ── importación: flujo HTTP ───────────────────────────────────────────────

    public function testUploadPreviewAndConfirmImportsTheSelection(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $preview = $this->upload($w, $this->exporter()->export($w['centre'], $w['sourceYear']));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1ºA', $preview->html());
        self::assertCount(1, $preview->filter('input[name="courses[]"]'));
        self::assertCount(1, $preview->filter('input[name="groups[0][]"]'));
        self::assertCount(1, $preview->filter('input[name="mode"][value="ignore"][checked]'), 'por defecto se ignora lo existente');

        $this->confirm($w, $preview, ['courses' => ['0'], 'groups' => [0 => ['0']], 'include_tutors' => '1', 'include_subjects' => '1', 'include_color' => '1', 'mode' => 'ignore']);

        self::assertResponseRedirects();
        $this->em->clear();
        $group = $this->targetGroup($w['targetYear'], '1ºA');
        self::assertSame('#d97706', $group->getColor());
        self::assertCount(1, $group->getTeacherAssignments());
    }

    public function testNothingSelectedKeepsThePreviewWithAnError(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $preview = $this->upload($w, $this->exporter()->export($w['centre'], $w['sourceYear']));

        $crawler = $this->confirm($w, $preview, ['mode' => 'ignore']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('al menos un curso o un grupo', $crawler->html());
    }

    public function testAnInvalidFileIsRejectedWithoutAPreview(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $this->upload($w, ['categories' => []]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no es una oferta formativa', $this->client->getResponse()->getContent() ?: '');
    }

    public function testConfirmWithoutAPreviewIsExpired(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $preview = $this->upload($w, $this->exporter()->export($w['centre'], $w['sourceYear']));

        // Un identificador que no es el de la vista previa de esta sesión (o cuyo fichero ya no está).
        $this->confirm($w, $preview, ['courses' => ['0'], 'import_id' => '00000000-0000-4000-8000-000000000000']);

        self::assertResponseRedirects($this->url($w['centre']) . '/import');
    }

    public function testConfirmWithAnInvalidCsrfIsDenied(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('POST', $this->url($w['centre']) . '/import/confirmar', ['_token' => 'malo', 'import_id' => 'x']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportIsDeniedWhileViewingAPastYear(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $this->viewPastYear($w['sourceYear']);

        $this->client->request('GET', $this->url($w['centre']) . '/import');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->url($w['centre']) . '/import/confirmar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOfferPageOffersImportOnlyForTheActiveYear(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $active = $this->client->request('GET', $this->url($w['centre']));
        self::assertCount(1, $active->filter('a[href$="/offer/import"]'));
        self::assertCount(1, $active->filter('a[href$="/offer/export"]'));

        $this->viewPastYear($w['sourceYear']);
        $past = $this->client->request('GET', $this->url($w['centre']));
        self::assertCount(0, $past->filter('a[href$="/offer/import"]'));
        self::assertCount(1, $past->filter('a[href$="/offer/export"]'), 'exportar un curso pasado sí se puede');
    }

    // ── ayudas ────────────────────────────────────────────────────────────────

    /**
     * Un centro con el curso 2024-2025 (con una oferta completa: curso, grupo con color, un tutor y una materia) y el curso
     * activo 2025-2026 (vacío), con los dos docentes en ambos.
     *
     * @return array{admin: Teacher, centre: EducationalCentre, sourceYear: AcademicYear, targetYear: AcademicYear, ana: Teacher, luis: Teacher}
     */
    private function makeWorld(): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Oferta')))->setUsername("admin.offer.$seq")->setAdmin(true);
        $ana    = (new Teacher(new PersonName('Ana', 'Tutora')))->setUsername("ana.tutora.$seq");
        $luis   = (new Teacher(new PersonName('Luis', 'Docente')))->setUsername("luis.docente.$seq");
        $centre = (new EducationalCentre())->setCode("4200{$seq}77")->setName("IES Oferta $seq")->setCity('Sevilla');
        $source = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $target = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        foreach ([$source, $target] as $year) {
            $year->addTeacher($ana);
            $year->addTeacher($luis);
        }
        $course = (new Course())->setName('1º ESO')->setAcademicYear($source)->setDetails('Primero');
        $group  = (new Group())->setName('1ºA')->setCourse($course)->setDetails('Segundo de prueba')->setColor('#d97706');
        $group->addTutor($ana);
        $group->addTeacher($luis, 'Matemáticas');

        $this->persist($admin, $ana, $luis, $centre, $source, $target, $course, $group);
        $centre->setActiveAcademicYear($target);
        $centre->addAdmin($admin);
        $this->flush();

        return ['admin' => $admin, 'centre' => $centre, 'sourceYear' => $source, 'targetYear' => $target, 'ana' => $ana, 'luis' => $luis];
    }

    /** Un grupo ya existente en el curso activo (curso «1º ESO», como el del fichero). @param array<string, mixed> $w */
    private function makeTargetGroup(array $w, string $name, ?string $color): Group
    {
        $course = null;
        foreach ($this->em->getRepository(Course::class)->findBy(['academicYear' => $w['targetYear']]) as $candidate) {
            $course = $candidate;
        }
        if ($course === null) {
            $course = (new Course())->setName('1º ESO')->setAcademicYear($w['targetYear']);
            $this->persist($course);
        }
        $group = (new Group())->setName($name)->setCourse($course)->setColor($color);
        $this->persist($group);
        $this->flush();

        return $group;
    }

    private function targetGroup(AcademicYear $year, string $name): ?Group
    {
        $this->em->clear();
        foreach ($this->em->getRepository(Group::class)->findAll() as $group) {
            if ($group->getName() === $name && $group->getCourse()->getAcademicYear()->getId()->equals($year->getId())) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $w
     * @param array<string, mixed> $content
     */
    private function upload(array $w, array $content): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->url($w['centre']) . '/import');
        $token   = $crawler->filter('[name="_token"]')->attr('value');
        $path    = tempnam(sys_get_temp_dir(), 'offer') . '.json';
        file_put_contents($path, json_encode($content));

        return $this->client->request(
            'POST',
            $this->url($w['centre']) . '/import',
            ['_token' => $token],
            ['json' => new UploadedFile($path, 'oferta.json', 'application/json', null, true)],
        );
    }

    /**
     * @param array<string, mixed> $w
     * @param array<string, mixed> $fields
     */
    private function confirm(array $w, \Symfony\Component\DomCrawler\Crawler $preview, array $fields): \Symfony\Component\DomCrawler\Crawler
    {
        $form = $preview->filter('form[action$="/import/confirmar"]');

        return $this->client->request('POST', $this->url($w['centre']) . '/import/confirmar', $fields + [
            '_token'    => $form->filter('[name="_token"]')->attr('value'),
            'import_id' => $form->filter('[name="import_id"]')->attr('value'),
        ]);
    }

    private function url(EducationalCentre $centre): string
    {
        return '/centro/' . $centre->getId()->toRfc4122() . '/offer';
    }

    private function exporter(): OfferExporter
    {
        return self::getContainer()->get(OfferExporter::class);
    }

    private function importer(): OfferImporter
    {
        return self::getContainer()->get(OfferImporter::class);
    }
}
