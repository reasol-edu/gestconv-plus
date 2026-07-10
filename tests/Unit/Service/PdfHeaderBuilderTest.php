<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Service\AppSettingsInterface;
use App\Service\PdfHeaderBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

class PdfHeaderBuilderTest extends TestCase
{
    /** Nombres de mes tal y como aparecen en translations/calendar.es.yaml (claves month.1..month.12) */
    private const MONTH_NAMES_ES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function testSubstitutesPlaceholdersOnBothSidesAndReadsMargin(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left'   => '<p><strong>{title}</strong></p>',
            'reports.incident_header_right'  => '<p>{centre_name}</p>',
            'reports.incident_header_margin' => 30,
        ]);

        $header = $builder->build('incident', $this->makeCentre(), [
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

        $header = $builder->build('incident', $this->makeCentre(), [
            'student_name' => 'Ana <script>alert(1)</script> & Cía',
        ]);

        self::assertSame('<p>Ana &lt;script&gt;alert(1)&lt;/script&gt; &amp; Cía</p>', $header->leftHtml);
    }

    public function testLeavesUnknownPlaceholdersUntouched(): void
    {
        $builder = $this->makeBuilder([
            'reports.sanction_header_left' => '<p>{title} {unknown_token}</p>',
        ]);

        $header = $builder->build('sanction', $this->makeCentre(), ['title' => 'Sanción']);

        self::assertSame('<p>Sanción {unknown_token}</p>', $header->leftHtml);
    }

    public function testSanitizesDisallowedMarkupBeforeSubstitution(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left' => '<script>alert(1)</script><p onclick="x()">{title}</p>',
        ]);

        $header = $builder->build('incident', $this->makeCentre(), ['title' => 'Informe']);

        self::assertSame('<p>Informe</p>', $header->leftHtml);
    }

    public function testFallsBackToEmptySidesAndDefaultMargin(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left'   => '',
            'reports.incident_header_right'  => null,
            'reports.incident_header_margin' => null,
        ]);

        $header = $builder->build('incident', $this->makeCentre(), []);

        self::assertSame('', $header->leftHtml);
        self::assertSame('', $header->rightHtml);
        self::assertSame(22, $header->marginTopMm);
    }

    public function testBuildFooterSubstitutesPlaceholders(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_footer' => '<p>{title} — {student_name}</p>',
        ]);

        $footer = $builder->buildFooter('incident', $this->makeCentre(), [
            'title'        => 'Informe de parte',
            'student_name' => 'Ana López',
        ]);

        self::assertSame('<p>Informe de parte — Ana López</p>', $footer);
    }

    public function testBuildFooterFallsBackToEmptyString(): void
    {
        $builder = $this->makeBuilder([
            'reports.sanction_footer' => null,
        ]);

        $footer = $builder->buildFooter('sanction', $this->makeCentre(), []);

        self::assertSame('', $footer);
    }

    public function testCityAndCurrentDatePlaceholdersAreAlwaysAvailable(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_header_left' => '<p>{city} — {current_date}</p>',
        ]);

        $header = $builder->build('incident', $this->makeCentre('Sevilla'), []);

        self::assertSame('<p>Sevilla — ' . (new \DateTimeImmutable())->format('d/m/Y') . '</p>', $header->leftHtml);
    }

    public function testCurrentDayMonthNameYearAndTimePlaceholdersAreAlwaysAvailable(): void
    {
        $builder = $this->makeBuilder([
            'reports.incident_footer' => '<p>{current_day} {current_month_name} {current_year} {current_time}</p>',
        ]);

        $footer = $builder->buildFooter('incident', $this->makeCentre(), []);

        $now = new \DateTimeImmutable();

        self::assertSame(
            \sprintf(
                '<p>%s %s %s %s</p>',
                $now->format('j'),
                mb_strtolower(self::MONTH_NAMES_ES[(int) $now->format('n')]),
                $now->format('Y'),
                $now->format('H:i'),
            ),
            $footer,
        );
    }

    public function testDefaultDatelineFooterResolvesPlaceholders(): void
    {
        $builder = $this->makeBuilder([
            'reports.sanction_footer' => '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>',
        ]);

        $footer = $builder->buildFooter('sanction', $this->makeCentre('Cádiz'), []);

        $now = new \DateTimeImmutable();

        self::assertSame(
            \sprintf(
                '<p>En Cádiz a %s de %s de %s</p>',
                $now->format('j'),
                mb_strtolower(self::MONTH_NAMES_ES[(int) $now->format('n')]),
                $now->format('Y'),
            ),
            $footer,
        );
    }

    private function makeCentre(string $city = 'Sevilla'): EducationalCentre
    {
        return (new EducationalCentre())->setCity($city);
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => $domain === 'calendar' && str_starts_with($id, 'month.')
                ? self::MONTH_NAMES_ES[(int) substr($id, 6)]
                : $id,
        );

        return new PdfHeaderBuilder($settings, $sanitizer, $translator);
    }
}
