<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\XlsxExporter;
use PHPUnit\Framework\TestCase;

class XlsxExporterTest extends TestCase
{
    /** El texto libre que empieza por «=» no se convierte en fórmula: se guarda como texto. */
    public function testTextStartingWithEqualsIsStoredAsTextNotAsAFormula(): void
    {
        $response = (new XlsxExporter())->createResponse('x.xlsx', ['Texto'], [
            ['=HYPERLINK("http://malo.example","clic")'],
            ['+1+1'],
            ['@SUM(A1)'],
            ['Normal'],
        ]);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($response->getFile()->getPathname()));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        self::assertStringNotContainsString('<f>', $sheet, 'No debe haber ninguna celda de fórmula.');
        self::assertStringNotContainsString('<f ', $sheet);
        self::assertStringContainsString('HYPERLINK', $sheet, 'El texto sí está, como texto.');
    }
}
