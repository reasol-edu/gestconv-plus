<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AttachmentZipExporter
{
    /**
     * @param list<array{name: string, content: string}> $entries
     */
    public function createResponse(string $zipFilename, array $entries): BinaryFileResponse
    {
        $tempPath = sys_get_temp_dir() . '/' . uniqid('gestconv_zip_', true) . '.zip';

        $zip = new \ZipArchive();
        $zip->open($tempPath, \ZipArchive::CREATE);

        $used = [];
        foreach ($entries as $entry) {
            $zip->addFromString($this->uniqueName($entry['name'], $used), $entry['content']);
        }

        $zip->close();

        if (!file_exists($tempPath)) {
            // ZipArchive no escribe ningún fichero al cerrar un archivo sin entradas.
            file_put_contents($tempPath, "PK\x05\x06" . str_repeat("\x00", 18));
        }

        $response = new BinaryFileResponse($tempPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipFilename);
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * @param array<string, int> $used
     */
    private function uniqueName(string $name, array &$used): string
    {
        if (!isset($used[$name])) {
            $used[$name] = 1;

            return $name;
        }

        $slash     = strrpos($name, '/');
        $directory = $slash === false ? '' : substr($name, 0, $slash + 1);
        $basename  = $slash === false ? $name : substr($name, $slash + 1);

        $dot       = strrpos($basename, '.');
        $stem      = $dot === false ? $basename : substr($basename, 0, $dot);
        $extension = $dot === false ? '' : substr($basename, $dot);

        do {
            $candidate = sprintf('%s%s (%d)%s', $directory, $stem, ++$used[$name], $extension);
        } while (isset($used[$candidate]));

        $used[$candidate] = 1;

        return $candidate;
    }
}
