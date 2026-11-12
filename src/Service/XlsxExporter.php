<?php

declare(strict_types=1);

namespace App\Service;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class XlsxExporter
{
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param list<string>                                        $headers
     * @param iterable<array<int|string, string|int|float|null>> $rows
     */
    public function createResponse(string $filename, array $headers, iterable $rows): BinaryFileResponse
    {
        $tempPath = sys_get_temp_dir() . '/' . uniqid('gestconv_export_', true) . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($tempPath);

        $headerStyle = (new Style())->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle($headers, $headerStyle));

        foreach ($rows as $row) {
            // Siempre celdas de texto explícitas: `Row::fromValues()` convertiría en FÓRMULA cualquier texto que
            // empiece por «=» (observaciones, descripciones y nombres son texto libre), lo que permitiría inyectar
            // fórmulas en la hoja que abre quien exporta.
            $writer->addRow(new Row(array_values(array_map(
                static fn (string|int|float|null $v): StringCell => new StringCell((string) ($v ?? ''), null),
                $row,
            ))));
        }

        $writer->close();

        $response = new BinaryFileResponse($tempPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
