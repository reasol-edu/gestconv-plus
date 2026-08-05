<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\SettingFile;
use App\Service\AppSettingsInterface;
use App\Service\PdfTemplateResolver;
use App\Service\ResolvedSettingFile;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class PdfTemplateResolverTest extends TestCase
{
    private AppSettingsInterface&Stub $settings;
    private PdfTemplateResolver $resolver;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->settings = $this->createStub(AppSettingsInterface::class);
        $this->resolver = new PdfTemplateResolver($this->settings);
        $this->centre   = $this->createStub(EducationalCentre::class);
    }

    public function testSpecificTemplateWinsOverGeneral(): void
    {
        $specific = $this->makeResolved('incident.pdf');
        $general  = $this->makeResolved('general-vertical.pdf');

        $this->settings->method('getFileForCentre')->willReturnMap([
            ['reports.incident_pdf_template', $this->centre, $specific],
            ['reports.pdf_template_portrait', $this->centre, $general],
        ]);

        $result = $this->resolver->resolve('incident', 'P', $this->centre);

        self::assertSame($specific, $result);
    }

    public function testFallsBackToGeneralPortrait(): void
    {
        $general = $this->makeResolved('general-vertical.pdf');

        $this->settings->method('getFileForCentre')->willReturnMap([
            ['reports.incident_pdf_template', $this->centre, null],
            ['reports.pdf_template_portrait', $this->centre, $general],
        ]);

        $result = $this->resolver->resolve('incident', 'P', $this->centre);

        self::assertSame($general, $result);
    }

    public function testFallsBackToGeneralLandscape(): void
    {
        $general = $this->makeResolved('general-apaisada.pdf');

        $this->settings->method('getFileForCentre')->willReturnMap([
            ['reports.guard_duty_pdf_template', $this->centre, null],
            ['reports.pdf_template_landscape', $this->centre, $general],
        ]);

        $result = $this->resolver->resolve('guard_duty', 'L', $this->centre);

        self::assertSame($general, $result);
    }

    public function testReturnsNullWhenNothingConfigured(): void
    {
        $this->settings->method('getFileForCentre')->willReturn(null);

        $result = $this->resolver->resolve('sanction', 'P', $this->centre);

        self::assertNull($result);
    }

    private function makeResolved(string $filename): ResolvedSettingFile
    {
        $file = new SettingFile('hash-' . $filename, 'contenido', 'application/pdf', 9);

        return new ResolvedSettingFile($file, $filename);
    }
}
