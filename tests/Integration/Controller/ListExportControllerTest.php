<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Exportación a Excel de los listados: mismas consultas por visor que las pantallas, con los filtros puestos. */
class ListExportControllerTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private static int $counter = 0;
    private int $reportNumber = 0;

    public function testAnonymousUsersAreRedirectedToLogin(): void
    {
        foreach (['partes', 'sanciones', 'notas', 'notificaciones', 'tutoria'] as $list) {
            $this->client->request('GET', '/exportar/' . $list);
            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        }
    }

    public function testIncidentExportIsAnXlsxWithTheRowsAndFiltersOfTheList(): void
    {
        $w = $this->world();
        $this->makeReport($w, $w['author'], 'Descripción uno');
        $other = $this->makeReport($w, $w['author'], 'Descripción dos');
        $other->setStudent($w['student2']);
        $this->flush();
        $this->loginAs($w['admin'], $w['centre']);

        $sheet = $this->download('/exportar/partes');
        self::assertStringContainsString('García, Ana', $sheet);
        self::assertStringContainsString('Pérez, Luis', $sheet);
        self::assertStringContainsString('Descripción uno', $sheet);

        $filtered = $this->download('/exportar/partes?search=' . rawurlencode('Pérez'));
        self::assertStringContainsString('Pérez, Luis', $filtered);
        self::assertStringNotContainsString('García, Ana', $filtered, 'El filtro de búsqueda de la pantalla se respeta.');
    }

    public function testAnUnrelatedTeacherOnlyExportsTheirOwnReports(): void
    {
        $w = $this->world();
        $this->makeReport($w, $w['author'], 'Parte ajeno secreto');
        $stranger = $this->teacher('stranger');
        $this->persist($stranger);
        $this->makeReport($w, $stranger, 'Parte propio');
        $this->loginAs($stranger, $w['centre']);

        $sheet = $this->download('/exportar/partes');

        self::assertStringContainsString('Parte propio', $sheet);
        self::assertStringNotContainsString('Parte ajeno secreto', $sheet, 'Nunca más filas de las que el visor ve en la lista.');
    }

    public function testFreeTextStartingWithEqualsIsNotExportedAsAFormula(): void
    {
        $w = $this->world();
        $this->makeReport($w, $w['author'], '=HYPERLINK("http://malo.example","clic")');
        $this->loginAs($w['admin'], $w['centre']);

        $response = $this->client->request('GET', '/exportar/partes');
        $sheet    = $this->sheetXml();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<f>', $sheet);
        self::assertStringContainsString('HYPERLINK', $sheet);
        self::assertNotNull($response);
    }

    public function testSanctionExportHonoursThePendingSenecaFilter(): void
    {
        $w   = $this->world();
        $one = $this->makeSanction($w, false);
        $two = $this->makeSanction($w, true);
        $two->setGroup($w['group2']);
        $this->flush();
        $this->loginAs($w['admin'], $w['centre']);

        self::assertStringContainsString('1ºB', $this->download('/exportar/sanciones'));
        $pending = $this->download('/exportar/sanciones?pendingSenecaOnly=1');
        self::assertStringContainsString('1ºA', $pending);
        self::assertStringNotContainsString('1ºB', $pending);
        self::assertNotNull($one);
    }

    public function testNoteExportIsNotFoundWhenTheNotesSectionIsDisabled(): void
    {
        $w          = $this->world();
        $definition = (new SettingDefinition())->setKey('features.daily_notes')->setType(SettingType::Boolean)
            ->setCategory('settings.category.features')->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new CentreSettingValue())->setDefinition($definition)->setCentre($w['centre'])->setValue('false'));
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/exportar/notas');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoteExportListsNotesWithTheirObservations(): void
    {
        $w    = $this->world();
        $type = (new DailyNoteType())->setEducationalCentre($w['centre'])->setName('Retraso')->setPosition(0);
        $this->persist($type);
        $this->persist((new DailyNote())->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setType($type)
            ->setRegisteredBy($w['author'])->setObservations('Llegó tarde'));
        $this->loginAs($w['admin'], $w['centre']);

        $sheet = $this->download('/exportar/notas');

        self::assertStringContainsString('Retraso', $sheet);
        self::assertStringContainsString('Llegó tarde', $sheet);
    }

    public function testTutorshipExportIsForbiddenToTeachersWhoTutorNoGroup(): void
    {
        $w = $this->world();
        $this->loginAs($w['author'], $w['centre']);

        $this->client->request('GET', '/exportar/tutoria');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTutorshipExportListsTheTutoredStudentsOnly(): void
    {
        $w = $this->world();
        $w['group']->addTutor($w['author']);
        $this->flush();
        $this->loginAs($w['author'], $w['centre']);

        $sheet = $this->download('/exportar/tutoria');

        self::assertStringContainsString('García, Ana', $sheet);
        self::assertStringNotContainsString('Pérez, Luis', $sheet, 'Luis está en un grupo que no tutoriza.');
    }

    public function testTheListScreensLinkToTheExportWithTheirCurrentFilters(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $component = $this->createLiveComponent('IncidentReportListComponent', ['centre' => $w['centre']], $this->client);
        $component->set('search', 'garcia');
        $href = (string) $component->render()->crawler()->filter('a[data-export-link]')->first()->attr('href');

        self::assertStringContainsString('/exportar/partes', $href);
        self::assertStringContainsString('search=garcia', $href);
    }

    public function testCommunicationExportIsAnXlsxForAdmin(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/exportar/notificaciones');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Descarga la exportación y devuelve el XML de la hoja (el texto va en línea, así que se puede buscar en él). */
    private function download(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));

        return $this->sheetXml();
    }

    private function sheetXml(): string
    {
        // El fichero temporal se borra al terminar la petición: se trabaja con el contenido que recibió el cliente.
        self::assertInstanceOf(BinaryFileResponse::class, $this->client->getResponse());
        $path = tempnam(sys_get_temp_dir(), 'gc_xlsx_');
        file_put_contents($path, $this->client->getInternalResponse()->getContent());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return html_entity_decode($xml, ENT_QUOTES | ENT_XML1);
    }

    private function teacher(string $name): Teacher
    {
        return (new Teacher(new PersonName('Test', ucfirst($name))))->setUsername($name . '.' . uniqid('', false))->setPassword('x');
    }

    /** @param array<string, mixed> $w */
    private function makeReport(array $w, Teacher $by, string $description): IncidentReport
    {
        $this->persist($by);
        $report = (new IncidentReport())
            ->setAcademicYear($w['year'])
            ->setNumber(++$this->reportNumber)
            ->setStudent($w['student'])
            ->setGroup($w['group'])
            ->setRegisteredBy($by)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription($description)
            ->setExpelledFromClass(false);
        $report->addBehavior($w['behavior']);
        $this->persist($report);

        return $report;
    }

    /** @param array<string, mixed> $w */
    private function makeSanction(array $w, bool $registeredInSeneca): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setRegisteredBy($w['admin'])
            ->setDetails('Detalle.')->setNoMeasureApplied(true)->setRegisteredInSeneca($registeredInSeneca);
        $this->persist($sanction);

        return $sanction;
    }

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, group2: Group, student: Student, student2: Student,
     *               admin: Teacher, author: Teacher, behavior: IncidentBehavior}
     */
    private function world(): array
    {
        $n        = ++self::$counter;
        $centre   = (new EducationalCentre())->setCode('8' . str_pad((string) $n, 7, '0', STR_PAD_LEFT))->setName('IES Export ' . $n)->setCity('Sevilla');
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course   = (new Course())->setName('DAW')->setAcademicYear($year);
        $group    = (new Group())->setName('1ºA')->setCourse($course);
        $group2   = (new Group())->setName('1ºB')->setCourse($course);
        $student  = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-E-' . $n);
        $student2 = (new Student(new PersonName('Luis', 'Pérez')))->setStudentId('NIE-F-' . $n);
        $student->addGroup($group);
        $student2->addGroup($group2);
        $admin    = $this->teacher('admin');
        $author   = $this->teacher('author');
        $category = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Contrarias')->setSerious(false)->setPosition(0);
        $behavior = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($category)->setName('Perturbación')->setPosition(0)->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $group2, $student, $student2, $admin, $author, $category, $behavior);
        $centre->addAdmin($admin);
        $this->flush();

        return compact('centre', 'year', 'group', 'group2', 'student', 'student2', 'admin', 'author', 'behavior');
    }
}
