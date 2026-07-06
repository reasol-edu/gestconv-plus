<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Service\AppSettingsInterface;
use App\Service\PdfHeaderBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class PdfHeaderBuilderTest extends TestCase
{
    public function testSubstitutesPlaceholdersOnBothSidesAndReadsMargin(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left'   => '<p><strong>{title}</strong></p>',
            'reports.incident_header_right'  => '<p>{centre_name}</p>',
            'reports.incident_header_margin' => 30,
        ]);

        $header = $builder->build('incident', new EducationalCentre(), [
            'title'       => 'Informe de parte de convivencia',
            'centre_name' => 'IES Azahar',
        ]);

        self::assertSame('<p><strong>Informe de parte de convivencia</strong></p>', $header->leftHtml);
        self::assertSame('<p>IES Azahar</p>', $header->rightHtml);
        self::assertSame(30, $header->marginTopMm);
    }

    public function testEscapesHtmlInPlaceholderValues(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left' => '<p>{student_name}</p>',
        ]);

        $header = $builder->build('incident', new EducationalCentre(), [
            'student_name' => 'Ana <script>alert(1)</script> & Cía',
        ]);

        self::assertSame('<p>Ana &lt;script&gt;alert(1)&lt;/script&gt; &amp; Cía</p>', $header->leftHtml);
    }

    public function testLeavesUnknownPlaceholdersUntouched(): void
    {
        $builder = $this->makeBuilder([
            'reports.sanction_header_left' => '<p>{title} {unknown_token}</p>',
        ]);

        $header = $builder->build('sanction', new EducationalCentre(), ['title' => 'Sanción']);

        self::assertSame('<p>Sanción {unknown_token}</p>', $header->leftHtml);
    }

    public function testSanitizesDisallowedMarkupBeforeSubstitution(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left' => '<script>alert(1)</script><p onclick="x()">{title}</p>',
        ]);

        $header = $builder->build('incident', new EducationalCentre(), ['title' => 'Informe']);

        self::assertSame('<p>Informe</p>', $header->leftHtml);
    }

    public function testFallsBackToEmptySidesAndDefaultMargin(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left'   => '',
            'reports.incident_header_right'  => null,
            'reports.incident_header_margin' => null,
        ]);

        $header = $builder->build('incident', new EducationalCentre(), []);

        self::assertSame('', $header->leftHtml);
        self::assertSame('', $header->rightHtml);
        self::assertSame(22, $header->marginTopMm);
    }

    /** @param array<string, mixed> $values valores por clave de ajuste */
    private function makeBuilder(array $values): PdfHeaderBuilder
    {
        $settings = $this->createStub(AppSettingsInterface::class);
        $settings->method('getForCentre')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? null,
        );

        $sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowElement('p')
                ->allowElement('br')
                ->allowElement('strong')
                ->allowElement('em')
                ->allowElement('u'),
        );

        return new PdfHeaderBuilder($settings, $sanitizer);
    }
}
