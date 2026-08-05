<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SettingPdfTemplateControllerTest extends ControllerTestCase
{
    private static int $counter = 0;

    // ── upload ────────────────────────────────────────────────────────────────

    public function testUploadStoresTemplateAndRedirects(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->makePdfFile('P'));

        self::assertResponseRedirects('/centro/' . $centre->getId()->toRfc4122() . '/ajustes');

        $value = $this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]);
        self::assertNotNull($value);
        self::assertNotNull($value->getFile());
        self::assertSame('membrete.pdf', $value->getValue());
    }

    public function testUploadingSameContentTwiceReusesTheSameSettingFile(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $pdf = $this->makeSinglePagePdfBytes('P');

        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->writeTempFile($pdf, 'a.pdf'));
        $this->uploadFile($centre, 'reports.sanction_pdf_template', $this->writeTempFile($pdf, 'b.pdf'));

        $incident = $this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]);
        $sanction = $this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.sanction_pdf_template')]);

        self::assertNotNull($incident);
        self::assertNotNull($sanction);
        self::assertSame($incident->getFile(), $sanction->getFile());
        self::assertSame(1, count($this->em->getRepository(SettingFile::class)->findAll()));
    }

    public function testUploadRejectsNonPdfMimeType(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $path = tempnam(sys_get_temp_dir(), 'txt_') . '.txt';
        file_put_contents($path, 'no soy un pdf');
        $file = new UploadedFile($path, 'nota.txt', 'text/plain', null, true);

        $this->uploadFile($centre, 'reports.incident_pdf_template', $file);

        self::assertResponseRedirects();
        self::assertNull($this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]));
    }

    public function testUploadRejectsOversizedFile(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $path = tempnam(sys_get_temp_dir(), 'big_') . '.pdf';
        file_put_contents($path, str_repeat('a', 11 * 1024 * 1024));
        $file = new UploadedFile($path, 'grande.pdf', 'application/pdf', null, true);

        $this->uploadFile($centre, 'reports.incident_pdf_template', $file);

        self::assertResponseRedirects();
        self::assertNull($this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]));
    }

    public function testUploadRejectsMultiPagePdf(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML('<p>1</p>');
        $mpdf->AddPage();
        $mpdf->WriteHTML('<p>2</p>');
        $content = $mpdf->Output('', Destination::STRING_RETURN);
        \assert(is_string($content));

        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->writeTempFile($content, 'dos-paginas.pdf'));

        self::assertResponseRedirects();
        self::assertNull($this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]));
    }

    public function testUploadRejectsWrongOrientation(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        // 'incident' espera vertical; se sube apaisado
        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->makePdfFile('L'));

        self::assertResponseRedirects();
        self::assertNull($this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]));
    }

    public function testUploadRejectsCorruptPdf(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->writeTempFile('%PDF-1.4 esto no continúa bien', 'roto.pdf'));

        self::assertResponseRedirects();
        self::assertNull($this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]));
    }

    public function testUploadIsDeniedToUnprivilegedTeacher(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('unprivileged.pdf.upload');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('POST', '/ajustes/plantillas-pdf/reports.incident_pdf_template/subir', [
            '_token' => 'irrelevant-since-denied-before-csrf-check',
        ], ['file' => $this->makePdfFile('P')]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadReturns404ForUnknownKey(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('POST', '/ajustes/plantillas-pdf/reports.does_not_exist/subir', [
            '_token' => 'irrelevant-since-404-before-csrf-check',
        ], ['file' => $this->makePdfFile('P')]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesOrphanedFile(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->makePdfFile('P'));
        $this->deleteTemplate($centre, 'reports.incident_pdf_template');

        self::assertResponseRedirects();
        self::assertNull($this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.incident_pdf_template')]));
        self::assertSame(0, count($this->em->getRepository(SettingFile::class)->findAll()));
    }

    public function testDeleteKeepsFileStillReferencedByAnotherSetting(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $pdf = $this->makeSinglePagePdfBytes('P');
        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->writeTempFile($pdf, 'a.pdf'));
        $this->uploadFile($centre, 'reports.sanction_pdf_template', $this->writeTempFile($pdf, 'b.pdf'));

        $this->deleteTemplate($centre, 'reports.incident_pdf_template');

        self::assertSame(1, count($this->em->getRepository(SettingFile::class)->findAll()));
        self::assertNotNull($this->em->getRepository(CentreSettingValue::class)
            ->findOneBy(['centre' => $centre, 'definition' => $this->definitionByKey('reports.sanction_pdf_template')]));
    }

    // ── download ──────────────────────────────────────────────────────────────

    public function testDownloadReturnsStoredContent(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->uploadFile($centre, 'reports.incident_pdf_template', $this->makePdfFile('P'));

        $this->client->request('GET', '/ajustes/plantillas-pdf/reports.incident_pdf_template/descargar');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testDownloadReturns404WhenNoTemplateStored(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/ajustes/plantillas-pdf/reports.incident_pdf_template/descargar');

        self::assertResponseStatusCodeSame(404);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $suffix = (string) ++self::$counter;
        $admin  = (new Teacher(new PersonName('Admin', 'Pdf')))->setUsername('admin.pdf.template.' . $suffix)->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('45' . str_pad($suffix, 6, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();

        return [$admin, $centre];
    }

    private function definitionByKey(string $key): ?SettingDefinition
    {
        return $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);
    }

    private function uploadFile(EducationalCentre $centre, string $key, UploadedFile $file): void
    {
        $token = $this->crawlToken($centre, 'form[action$="' . $key . '/subir"] [name="_token"]');

        $this->client->request(
            'POST',
            '/ajustes/plantillas-pdf/' . $key . '/subir',
            ['_token' => $token],
            ['file' => $file],
        );
    }

    private function deleteTemplate(EducationalCentre $centre, string $key): void
    {
        $token = $this->crawlToken($centre, 'form[action$="' . $key . '/eliminar"] [name="_token"]');

        $this->client->request('POST', '/ajustes/plantillas-pdf/' . $key . '/eliminar', ['_token' => $token]);
    }

    /** Fetches the centre settings page and reads a CSRF token off one of its embedded forms. */
    private function crawlToken(EducationalCentre $centre, string $selector): string
    {
        $crawler = $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ajustes');

        return (string) $crawler->filter($selector)->first()->attr('value');
    }

    private function makePdfFile(string $orientation): UploadedFile
    {
        return $this->writeTempFile($this->makeSinglePagePdfBytes($orientation), 'membrete.pdf');
    }

    private function writeTempFile(string $content, string $originalName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdftest_') . '.pdf';
        file_put_contents($path, $content);

        return new UploadedFile($path, $originalName, 'application/pdf', null, true);
    }

    private function makeSinglePagePdfBytes(string $orientation): string
    {
        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'orientation' => $orientation]);
        $mpdf->WriteHTML('<p>Membrete de prueba</p>');
        $content = $mpdf->Output('', Destination::STRING_RETURN);
        \assert(is_string($content));

        return $content;
    }
}
