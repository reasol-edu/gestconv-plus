<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\PrintableCalendar;
use App\Entity\Teacher;
use App\Repository\PrintableCalendarRepository;
use App\Tests\Integration\ControllerTestCase;

/** Utilidades › Generador de calendarios: personal, per-owner CRUD and its PDF. */
final class CalendarGeneratorControllerTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $owner;
    private Teacher $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->owner    = (new Teacher(new PersonName('Nombre', 'docente')))->setUsername('docente');
        $this->stranger = (new Teacher(new PersonName('Nombre', 'otro')))->setUsername('otro');
        $this->persist($this->centre, $this->year, $this->owner, $this->stranger);
    }

    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function baseFormValues(): array
    {
        return [
            'title'              => 'Calendario FCT',
            'description'        => 'Prácticas en empresa',
            'academicYear'       => $this->year->getId()->toRfc4122(),
            'startDate'          => '',
            'endDate'            => '',
            'nonWorkingDayColor' => PrintableCalendar::DEFAULT_NON_WORKING_DAY_COLOR,
            'weekendColor'       => PrintableCalendar::DEFAULT_WEEKEND_COLOR,
            'orientation'        => 'portrait',
            'fontSizeScale'      => '100',
            'showHeader'         => '1',
            'showFooter'         => '1',
            'dates'              => [],
            'periods'            => [],
        ];
    }

    private function create(array $overrides = []): string
    {
        $this->loginAs($this->owner, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios/nuevo');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/utilidades/generador-calendarios/nuevo', array_replace(
            $this->baseFormValues(),
            ['_token' => $this->csrfToken('utilities_calendar')],
            $overrides,
        ));
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertMatchesRegularExpression('#/generador-calendarios/([0-9a-f-]+)/editar$#', $location, $location);
        preg_match('#/generador-calendarios/([0-9a-f-]+)/editar$#', $location, $matches);

        return $matches[1];
    }

    public function testCreatingACalendarWithAPeriodAndAnIndividualDateSavesItForItsOwner(): void
    {
        $id = $this->create([
            'periods' => [[
                'id' => '', 'description' => 'Evaluación', 'color' => '#fca5a5', 'showJourneySummary' => '1', 'mode' => 'date_range',
                'startDate' => '2026-03-09', 'endDate' => '2026-03-11', 'totalHours' => '',
                'mondayHours' => '', 'tuesdayHours' => '', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => '',
            ]],
            'dates' => [[
                'id' => '', 'date' => '2026-03-10', 'color' => '#a7f3d0', 'description' => 'Graduación',
            ]],
        ]);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        self::assertSame('Calendario FCT', $calendar->getTitle());
        self::assertCount(1, $calendar->getPeriods());
        self::assertCount(1, $calendar->getDates());
    }

    public function testTheCompactJourneySummaryIsPersistedOnlyWithTheSummaryEnabled(): void
    {
        $period = static fn (string $description, string $summary): array => [
            'id' => '', 'description' => $description, 'color' => '#fca5a5', 'showJourneySummary' => $summary, 'compactJourneySummary' => '1', 'mode' => 'date_range',
            'startDate' => '2026-03-09', 'endDate' => '2026-03-11', 'totalHours' => '',
            'mondayHours' => '', 'tuesdayHours' => '', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => '',
        ];
        $id = $this->create(['periods' => [$period('Con resumen', '1'), $period('Sin resumen', '')]]);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        $periods = $calendar->getPeriods()->toArray();
        self::assertTrue($periods[0]->isCompactJourneySummary());
        self::assertFalse($periods[1]->isCompactJourneySummary());
    }

    public function testTheOrientationAndFontSizeScaleArePersisted(): void
    {
        $id = $this->create(['orientation' => 'landscape', 'fontSizeScale' => '150']);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        self::assertSame(\App\Entity\PrintableCalendarOrientation::Landscape, $calendar->getOrientation());
        self::assertSame(150, $calendar->getFontSizeScale());
    }

    public function testAFontSizeScaleOutsideFiftyToTwoHundredIsRejected(): void
    {
        $this->loginAs($this->owner, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios/nuevo');
        $this->client->request('POST', '/utilidades/generador-calendarios/nuevo', array_replace(
            $this->baseFormValues(),
            ['_token' => $this->csrfToken('utilities_calendar'), 'fontSizeScale' => '300'],
        ));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testHidingTheHeaderAndFooterIsPersisted(): void
    {
        $id = $this->create(['showHeader' => '', 'showFooter' => '']);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        self::assertFalse($calendar->isShowHeader());
        self::assertFalse($calendar->isShowFooter());
    }

    public function testShowingTheHoursIsAPerPeriodChoiceOnlyOfferedByTheModesWithHours(): void
    {
        $hoursPeriod = static fn (string $description, string $showHours): array => [
            'id' => '', 'description' => $description, 'color' => '#c7d2fe', 'showJourneySummary' => '', 'showHours' => $showHours, 'mode' => 'start_with_hours',
            'startDate' => '2027-01-04', 'endDate' => '', 'totalHours' => '16',
            'mondayHours' => '8', 'tuesdayHours' => '8', 'wednesdayHours' => '8', 'thursdayHours' => '8', 'fridayHours' => '8',
        ];
        $id = $this->create(['periods' => [
            $hoursPeriod('Con horas', '1'),
            $hoursPeriod('Sin horas', ''),
            [
                'id' => '', 'description' => 'Rango', 'color' => '#fca5a5', 'showJourneySummary' => '', 'showHours' => '1', 'mode' => 'date_range',
                'startDate' => '2027-02-01', 'endDate' => '2027-02-05', 'totalHours' => '',
                'mondayHours' => '', 'tuesdayHours' => '', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => '',
            ],
        ]]);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        $byName = [];
        foreach ($calendar->getPeriods() as $period) {
            $byName[$period->getDescription()] = $period->isShowHours();
        }
        self::assertTrue($byName['Con horas']);
        self::assertFalse($byName['Sin horas']);

        // The checkbox lives inside the blocks of the two modes with hours — never in the date-range one.
        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/editar');
        $crawler = $this->client->getCrawler();
        self::assertGreaterThan(0, $crawler->filter('[data-mode-switch-mode="start_with_hours"] input[type="checkbox"][name$="[showHours]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-mode-switch-mode="end_with_hours"] input[type="checkbox"][name$="[showHours]"]')->count());
        self::assertSame(0, $crawler->filter('[data-mode-switch-mode="date_range"] input[name$="[showHours]"]')->count());
        self::assertSame(0, $crawler->filter('input[name="showHours"]')->count(), 'no calendar-wide checkbox any more');
    }

    /**
     * The exact Twig context PdfRenderer builds for this template (CalendarGeneratorController::
     * pdf()) — rendered directly, not through the PDF response, since mPDF's output is a
     * compressed binary where a plain string search would pass trivially either way.
     */
    private function renderPdfHtml(PrintableCalendar $calendar): string
    {
        $data = self::getContainer()->get(\App\Service\PrintableCalendarPdfBuilder::class)->build($calendar);

        return self::getContainer()->get('twig')->render('utilities/pdf/calendar.html.twig', [
            'centre'   => $calendar->getEducationalCentre(),
            'calendar' => $calendar,
            'data'     => $data,
        ]);
    }

    public function testTheDescriptionIsStoredAsRichHtmlAndSanitizedOnlyWhenRendered(): void
    {
        $id = $this->create(['description' => '<p>Ojo con <strong>esto</strong></p><script>alert(1)</script>']);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        // Stored raw, script tag included — sanitize_html('app.rich_text') strips it at render time only.
        self::assertStringContainsString('<script>', (string) $calendar->getDescription());

        $html = $this->renderPdfHtml($calendar);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('alert(1)', $html);
        self::assertStringContainsString('<strong>esto</strong>', $html);

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/pdf');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheTitleIsShownCenteredInContentOnlyWhenTheRunningHeaderIsHidden(): void
    {
        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo = self::getContainer()->get(PrintableCalendarRepository::class);

        $shown = $repo->findByOwnerAndId($this->owner, $this->centre, $this->create(['title' => 'Con encabezado']));
        self::assertNotNull($shown);
        $htmlWithHeader = $this->renderPdfHtml($shown);
        // "cal-title" itself always appears in the <style> block's selector — check for the element.
        self::assertStringNotContainsString('<h1 class="cal-title">', $htmlWithHeader);

        $this->em->clear();
        $hidden = $repo->findByOwnerAndId($this->owner, $this->centre, $this->create(['title' => 'Sin encabezado', 'showHeader' => '']));
        self::assertNotNull($hidden);
        $htmlWithoutHeader = $this->renderPdfHtml($hidden);
        self::assertStringContainsString('<h1 class="cal-title">Sin encabezado</h1>', $htmlWithoutHeader);
    }

    public function testTheJourneySummaryBoxMirrorsTheMonthRowColumnsSoItsWidthMatchesTheTwoMonths(): void
    {
        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $this->create([
            'periods' => [[
                'id' => '', 'description' => 'FCT corta', 'color' => '#c7d2fe', 'showJourneySummary' => '1', 'mode' => 'start_with_hours',
                'startDate' => '2027-01-04', 'endDate' => '', 'totalHours' => '72',
                'mondayHours' => '8', 'tuesdayHours' => '8', 'wednesdayHours' => '8', 'thursdayHours' => '8', 'fridayHours' => '8',
            ]],
        ]));
        self::assertNotNull($calendar);

        $html = $this->renderPdfHtml($calendar);
        // Same 4-column structure as table.month-row (annotation-col, colspan="2" over the two
        // month-col widths, annotation-col) is what keeps table-layout: fixed sizing the journey
        // box to line up with the two months below it — a plain full-width box would not.
        self::assertMatchesRegularExpression(
            '#<table class="journey-row">\s*<tr>\s*<td class="annotation-col"></td>\s*<td class="journey-box-cell" colspan="2">#',
            $html,
        );
    }

    public function testTwoIndividualDatesOnTheSameDaySyncTheirColorToTheFirstOnSave(): void
    {
        $id = $this->create([
            'dates' => [
                ['id' => '', 'date' => '2026-03-10', 'color' => '#a7f3d0', 'description' => 'Entrega de notas'],
                ['id' => '', 'date' => '2026-03-10', 'color' => '#fca5a5', 'description' => 'Claustro'],
            ],
        ]);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        $colors = array_map(static fn ($d) => $d->getColor(), $calendar->getDates()->toArray());
        self::assertCount(2, $colors);
        self::assertSame($colors[0], $colors[1]);
        self::assertSame('#a7f3d0', $colors[0]);
    }

    public function testEditingReplacesThePeriodsAndDatesThatWereSubmitted(): void
    {
        $id = $this->create([
            'dates' => [['id' => '', 'date' => '2026-03-10', 'color' => '#a7f3d0', 'description' => 'Graduación']],
        ]);

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/editar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/editar', array_replace(
            $this->baseFormValues(),
            [
                '_token' => $this->csrfToken('utilities_calendar_' . $id),
                'title'  => 'Calendario FCT (revisado)',
                'dates'  => [['id' => '', 'date' => '2026-04-01', 'color' => '#a7f3d0', 'description' => 'Nueva fecha']],
            ],
        ));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);
        self::assertSame('Calendario FCT (revisado)', $calendar->getTitle());
        self::assertCount(1, $calendar->getDates());
        self::assertSame('Nueva fecha', $calendar->getDates()->first()->getDescription());
    }

    public function testSavingWithTheSaveAndPdfButtonRedirectsStraightToThePdf(): void
    {
        $id = $this->create();

        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/editar', array_replace(
            $this->baseFormValues(),
            ['_token' => $this->csrfToken('utilities_calendar_' . $id), 'action' => 'save_and_pdf'],
        ));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            '/utilidades/generador-calendarios/' . $id . '/pdf',
            $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testDuplicatingACalendarCopiesItsDatesAndPeriodsUnderASuffixedTitle(): void
    {
        $id = $this->create([
            'periods' => [[
                'id' => '', 'description' => 'Evaluación', 'color' => '#fca5a5', 'showJourneySummary' => '1', 'mode' => 'date_range',
                'startDate' => '2026-03-09', 'endDate' => '2026-03-11', 'totalHours' => '',
                'mondayHours' => '', 'tuesdayHours' => '', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => '',
            ]],
            'dates' => [['id' => '', 'date' => '2026-03-10', 'color' => '#a7f3d0', 'description' => 'Graduación']],
        ]);

        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/duplicar', [
            '_token' => $this->csrfToken('utilities_calendar_duplicate_' . $id),
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        preg_match('#/generador-calendarios/([0-9a-f-]+)/editar$#', $location, $matches);
        $copyId = $matches[1];
        self::assertNotSame($id, $copyId);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo = self::getContainer()->get(PrintableCalendarRepository::class);
        $copy = $repo->findByOwnerAndId($this->owner, $this->centre, $copyId);
        self::assertNotNull($copy);
        self::assertSame('Calendario FCT (copia)', $copy->getTitle());
        self::assertCount(1, $copy->getPeriods());
        self::assertCount(1, $copy->getDates());

        // The original is untouched.
        $original = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($original);
        self::assertSame('Calendario FCT', $original->getTitle());
    }

    public function testExportingACalendarReturnsADownloadableJsonFileAndImportingItRecreatesTheCalendar(): void
    {
        $id = $this->create([
            'periods' => [[
                'id' => '', 'description' => 'FCT corta', 'color' => '#c7d2fe', 'showJourneySummary' => '1', 'mode' => 'start_with_hours',
                'startDate' => '2027-01-04', 'endDate' => '', 'totalHours' => '40',
                'mondayHours' => '8', 'tuesdayHours' => '8', 'wednesdayHours' => '8', 'thursdayHours' => '8', 'fridayHours' => '8',
            ]],
            'dates' => [['id' => '', 'date' => '2026-03-10', 'color' => '#a7f3d0', 'description' => 'Graduación']],
        ]);

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/exportar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $json = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('Calendario FCT', $decoded['calendar']['title']);
        self::assertCount(1, $decoded['calendar']['dates']);
        self::assertCount(1, $decoded['calendar']['periods']);
        // Portable across centres/years: the export never carries academicYear/centre identifiers.
        self::assertArrayNotHasKey('academicYear', $decoded['calendar']);

        $this->client->request('GET', '/utilidades/generador-calendarios/importar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/utilidades/generador-calendarios/importar', [
            '_token'       => $this->csrfToken('utilities_calendar_import'),
            'academicYear' => $this->year->getId()->toRfc4122(),
        ], [
            'json' => $this->uploadedJsonFile($json),
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        preg_match('#/generador-calendarios/([0-9a-f-]+)/editar$#', $location, $matches);

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo      = self::getContainer()->get(PrintableCalendarRepository::class);
        $imported  = $repo->findByOwnerAndId($this->owner, $this->centre, $matches[1]);
        self::assertNotNull($imported);
        self::assertSame('Calendario FCT', $imported->getTitle());
        self::assertCount(1, $imported->getPeriods());
        self::assertCount(1, $imported->getDates());
    }

    public function testImportingAMalformedJsonFileShowsAnErrorInsteadOfCreatingACalendar(): void
    {
        $this->loginAs($this->owner, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios/importar');

        $this->client->request('POST', '/utilidades/generador-calendarios/importar', [
            '_token'       => $this->csrfToken('utilities_calendar_import'),
            'academicYear' => $this->year->getId()->toRfc4122(),
        ], [
            'json' => $this->uploadedJsonFile('{"not":"a calendar"}'),
        ]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        /** @var PrintableCalendarRepository $repo */
        $repo = self::getContainer()->get(PrintableCalendarRepository::class);
        self::assertSame([], $repo->findByOwnerAndYear($this->owner, $this->year));
    }

    private function uploadedJsonFile(string $content): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'calendar_import_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'calendario.json', 'application/json', null, true);
    }

    /** @return array<string, mixed> a sequence row of the form, UD1/UD2/UD3 from Monday 2 March 2026, 8 h a day */
    private function sequenceRow(array $overrides = []): array
    {
        return array_replace([
            'id' => '', 'name' => 'Unidades didácticas', 'startDate' => '2026-03-02', 'colorA' => '#bfdbfe', 'colorB' => '#fde68a',
            'showHours' => '1', 'showJourneySummary' => '1',
            'mondayHours' => '8', 'tuesdayHours' => '8', 'wednesdayHours' => '8', 'thursdayHours' => '8', 'fridayHours' => '8',
            'items' => [
                ['description' => 'UD1', 'hours' => '20'],
                ['description' => 'UD2', 'hours' => '10'],
                ['description' => 'UD3', 'hours' => '12'],
            ],
            'paste' => '',
        ], $overrides);
    }

    private function loadCalendar(string $id): PrintableCalendar
    {
        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo     = self::getContainer()->get(PrintableCalendarRepository::class);
        $calendar = $repo->findByOwnerAndId($this->owner, $this->centre, $id);
        self::assertNotNull($calendar);

        return $calendar;
    }

    /** @return list<string> «UD1:20», «UD2:10»… in order */
    private function itemSummary(PrintableCalendar $calendar, int $sequence = 0): array
    {
        $summary = [];
        foreach ($calendar->getSequences()->get($sequence)?->getItems() ?? [] as $item) {
            $summary[] = $item->getDescription() . ':' . $item->getHours();
        }

        return $summary;
    }

    public function testASequenceIsSavedWithItsItemsInTheOrderSubmittedAndItsPdfRenders(): void
    {
        $id = $this->create(['sequences' => [$this->sequenceRow()]]);

        $calendar = $this->loadCalendar($id);
        self::assertCount(1, $calendar->getSequences());
        $sequence = $calendar->getSequences()->first();
        self::assertSame('Unidades didácticas', $sequence->getName());
        self::assertSame(['UD1:20', 'UD2:10', 'UD3:12'], $this->itemSummary($calendar));
        self::assertSame(8.0, $sequence->weekdayHours()[3]);

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/pdf');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/pdf', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testTheEditFormShowsTheSequenceAndItsItems(): void
    {
        $id = $this->create(['sequences' => [$this->sequenceRow()]]);

        $crawler = $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/editar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Unidades didácticas', $crawler->filter('input[name="sequences[0][name]"]')->attr('value'));
        self::assertSame('UD2', $crawler->filter('input[name="sequences[0][items][1][description]"]')->attr('value'));
        self::assertSame('12', $crawler->filter('input[name="sequences[0][items][2][hours]"]')->attr('value'));
    }

    public function testEditingReordersAndReplacesTheItemsAndRemovingASequenceDeletesIt(): void
    {
        $id       = $this->create(['sequences' => [$this->sequenceRow()]]);
        $sequence = $this->loadCalendar($id)->getSequences()->first();
        $seqId    = $sequence->getId()->toRfc4122();

        // Reordered (UD3 first) and with a new one with decimal hours.
        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/editar');
        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/editar', array_replace(
            $this->baseFormValues(),
            ['_token' => $this->csrfToken('utilities_calendar_' . $id)],
            ['sequences' => [$this->sequenceRow(['id' => $seqId, 'items' => [
                ['description' => 'UD3', 'hours' => '12'],
                ['description' => 'UD1', 'hours' => '20'],
                ['description' => 'Repaso', 'hours' => '7,5'],
            ]])]],
        ));
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $calendar = $this->loadCalendar($id);
        self::assertCount(1, $calendar->getSequences());
        self::assertSame($seqId, $calendar->getSequences()->first()->getId()->toRfc4122(), 'the same sequence, updated in place');
        self::assertSame(['UD3:12', 'UD1:20', 'Repaso:7.5'], $this->itemSummary($calendar));

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/editar');
        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/editar', array_replace(
            $this->baseFormValues(),
            ['_token' => $this->csrfToken('utilities_calendar_' . $id)],
            ['sequences' => [$this->sequenceRow(['id' => $seqId, 'remove' => '1'])]],
        ));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->loadCalendar($id)->getSequences());
    }

    public function testPastedItemsAreAddedAfterTheOnesAlreadyListed(): void
    {
        // A decimal comma stays part of the hours: only «;» and tab separate the description.
        $id = $this->create(['sequences' => [$this->sequenceRow([
            'items' => [['description' => 'UD1', 'hours' => '20']],
            'paste' => "UD2; 10\nUD3\t7,5\n\n  Repaso final ; 2  ",
        ])]]);

        self::assertSame(['UD1:20', 'UD2:10', 'UD3:7.5', 'Repaso final:2'], $this->itemSummary($this->loadCalendar($id)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSequences(): iterable
    {
        yield 'without a name' => [['name' => '']];
        yield 'without a start date' => [['startDate' => '']];
        yield 'with an invalid colour' => [['colorB' => 'rojo']];
        yield 'without hours on any weekday' => [['mondayHours' => '', 'tuesdayHours' => '0', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => '']];
        yield 'without items' => [['items' => []]];
        yield 'with an item without hours' => [['items' => [['description' => 'UD1', 'hours' => '']]]];
        yield 'with an item with zero hours' => [['items' => [['description' => 'UD1', 'hours' => '0']]]];
        yield 'with a pasted line without hours' => [['items' => [['description' => 'UD1', 'hours' => '5']], 'paste' => 'UD2 sin horas']];
    }

    /** @param array<string, mixed> $overrides */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSequences')]
    public function testAnInvalidSequenceIsRejectedAndNothingIsSaved(array $overrides): void
    {
        $this->loginAs($this->owner, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios/nuevo');
        $this->client->request('POST', '/utilidades/generador-calendarios/nuevo', array_replace(
            $this->baseFormValues(),
            ['_token' => $this->csrfToken('utilities_calendar'), 'sequences' => [$this->sequenceRow($overrides)]],
        ));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        /** @var PrintableCalendarRepository $repo */
        $repo = self::getContainer()->get(PrintableCalendarRepository::class);
        self::assertSame([], $repo->findByOwnerAndYear($this->owner, $this->year));
    }

    public function testASpareBlankSequenceRowIsIgnored(): void
    {
        $blank = $this->sequenceRow(['name' => '', 'startDate' => '', 'items' => [['description' => '', 'hours' => '']], 'mondayHours' => '', 'tuesdayHours' => '', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => '']);

        $id = $this->create(['sequences' => [$this->sequenceRow(), $blank]]);

        self::assertCount(1, $this->loadCalendar($id)->getSequences());
    }

    public function testDuplicatingExportingAndImportingACalendarKeepTheSequencesAndTheirItemsInOrder(): void
    {
        $id = $this->create(['sequences' => [
            $this->sequenceRow(),
            $this->sequenceRow(['name' => 'Segunda', 'showJourneySummary' => '', 'showHours' => '', 'items' => [['description' => 'B', 'hours' => '3,5'], ['description' => 'A', 'hours' => '4']]]),
        ]]);

        // Duplicate.
        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/duplicar', [
            '_token' => $this->csrfToken('utilities_calendar_duplicate_' . $id),
        ]);
        preg_match('#/generador-calendarios/([0-9a-f-]+)/editar$#', (string) $this->client->getResponse()->headers->get('Location'), $matches);
        $copy = $this->loadCalendar($matches[1]);
        self::assertCount(2, $copy->getSequences());
        self::assertSame(['UD1:20', 'UD2:10', 'UD3:12'], $this->itemSummary($copy, 0));
        self::assertSame(['B:3.5', 'A:4'], $this->itemSummary($copy, 1));
        self::assertFalse($copy->getSequences()->get(1)->isShowJourneySummary());
        self::assertFalse($copy->getSequences()->get(1)->isShowHours());

        // Export, then import.
        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/exportar');
        $json    = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($json, true);
        self::assertCount(2, $decoded['calendar']['sequences']);
        self::assertSame('Segunda', $decoded['calendar']['sequences'][1]['name']);

        $this->client->request('GET', '/utilidades/generador-calendarios/importar');
        $this->client->request('POST', '/utilidades/generador-calendarios/importar', [
            '_token'       => $this->csrfToken('utilities_calendar_import'),
            'academicYear' => $this->year->getId()->toRfc4122(),
        ], ['json' => $this->uploadedJsonFile($json)]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        preg_match('#/generador-calendarios/([0-9a-f-]+)/editar$#', (string) $this->client->getResponse()->headers->get('Location'), $matches);
        $imported = $this->loadCalendar($matches[1]);
        self::assertCount(2, $imported->getSequences());
        self::assertSame(['UD1:20', 'UD2:10', 'UD3:12'], $this->itemSummary($imported, 0));
        self::assertSame(['B:3.5', 'A:4'], $this->itemSummary($imported, 1));
        self::assertSame('2026-03-02', $imported->getSequences()->first()->getStartDate()->format('Y-m-d'));
        self::assertSame('#fde68a', $imported->getSequences()->first()->getColorB());
    }

    public function testAFileExportedBeforeSequencesExistedStillImports(): void
    {
        $json = (string) json_encode(['version' => 1, 'calendar' => [
            'title' => 'Antiguo', 'description' => null, 'startDate' => null, 'endDate' => null,
            'nonWorkingDayColor' => null, 'weekendColor' => null, 'orientation' => 'portrait', 'fontSizeScale' => 100,
            'dates' => [], 'periods' => [],
        ]]);
        $this->loginAs($this->owner, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios/importar');
        $this->client->request('POST', '/utilidades/generador-calendarios/importar', [
            '_token'       => $this->csrfToken('utilities_calendar_import'),
            'academicYear' => $this->year->getId()->toRfc4122(),
        ], ['json' => $this->uploadedJsonFile($json)]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        preg_match('#/generador-calendarios/([0-9a-f-]+)/editar$#', (string) $this->client->getResponse()->headers->get('Location'), $matches);
        self::assertCount(0, $this->loadCalendar($matches[1])->getSequences());
    }

    public function testAnImportedSequenceWithInvalidDataIsRejected(): void
    {
        $sequence = ['name' => 'S', 'startDate' => '2026-03-02', 'colorA' => '#bfdbfe', 'colorB' => '#fde68a', 'mondayHours' => 8, 'items' => [['description' => 'UD1', 'hours' => 0]]];
        $json     = (string) json_encode(['version' => 1, 'calendar' => [
            'title' => 'X', 'orientation' => 'portrait', 'fontSizeScale' => 100, 'dates' => [], 'periods' => [], 'sequences' => [$sequence],
        ]]);
        $this->loginAs($this->owner, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios/importar');
        $this->client->request('POST', '/utilidades/generador-calendarios/importar', [
            '_token'       => $this->csrfToken('utilities_calendar_import'),
            'academicYear' => $this->year->getId()->toRfc4122(),
        ], ['json' => $this->uploadedJsonFile($json)]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testDeletingRemovesTheCalendar(): void
    {
        $id = $this->create();

        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/eliminar', [
            '_token' => $this->csrfToken('utilities_calendar_' . $id),
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var PrintableCalendarRepository $repo */
        $repo = self::getContainer()->get(PrintableCalendarRepository::class);
        self::assertNull($repo->findByOwnerAndId($this->owner, $this->centre, $id));
    }

    public function testThePdfEndpointReturnsAPdfDocument(): void
    {
        $id = $this->create();

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/pdf');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/pdf', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testAnotherTeachersCalendarIsNeverReachableByOwnerOrCentre(): void
    {
        $id = $this->create();

        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/editar');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/pdf');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/utilidades/generador-calendarios/' . $id . '/exportar');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/duplicar', [
            '_token' => $this->csrfToken('utilities_calendar_duplicate_' . $id),
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/utilidades/generador-calendarios/' . $id . '/eliminar', [
            '_token' => $this->csrfToken('utilities_calendar_' . $id),
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheIndexOnlyListsTheOwnersOwnCalendarsForTheSelectedYear(): void
    {
        $this->create();

        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', '/utilidades/generador-calendarios');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Calendario FCT', (string) $this->client->getResponse()->getContent());
    }
}
