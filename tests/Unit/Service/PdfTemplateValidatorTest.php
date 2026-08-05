<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PdfTemplateValidationError;
use App\Service\PdfTemplateValidator;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PHPUnit\Framework\TestCase;

class PdfTemplateValidatorTest extends TestCase
{
    private PdfTemplateValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PdfTemplateValidator();
    }

    public function testValidSinglePagePortraitPdfPassesForPortraitSlot(): void
    {
        $pdf = $this->makePdf('P');

        self::assertNull($this->validator->validate($pdf, 'P'));
    }

    public function testValidSinglePageLandscapePdfPassesForLandscapeSlot(): void
    {
        $pdf = $this->makePdf('L');

        self::assertNull($this->validator->validate($pdf, 'L'));
    }

    public function testPortraitPdfIsRejectedForLandscapeSlot(): void
    {
        $pdf = $this->makePdf('P');

        self::assertSame(PdfTemplateValidationError::WrongOrientation, $this->validator->validate($pdf, 'L'));
    }

    public function testLandscapePdfIsRejectedForPortraitSlot(): void
    {
        $pdf = $this->makePdf('L');

        self::assertSame(PdfTemplateValidationError::WrongOrientation, $this->validator->validate($pdf, 'P'));
    }

    public function testMultiPagePdfIsRejected(): void
    {
        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML('<p>Página 1</p>');
        $mpdf->AddPage();
        $mpdf->WriteHTML('<p>Página 2</p>');
        $content = $mpdf->Output('', Destination::STRING_RETURN);
        \assert(is_string($content));

        self::assertSame(PdfTemplateValidationError::MultiPage, $this->validator->validate($content, 'P'));
    }

    public function testCorruptContentIsRejectedAsInvalid(): void
    {
        self::assertSame(PdfTemplateValidationError::InvalidPdf, $this->validator->validate('esto no es un pdf', 'P'));
    }

    private function makePdf(string $orientation): string
    {
        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'orientation' => $orientation]);
        $mpdf->WriteHTML('<p>Membrete de prueba</p>');
        $content = $mpdf->Output('', Destination::STRING_RETURN);
        \assert(is_string($content));

        return $content;
    }
}
